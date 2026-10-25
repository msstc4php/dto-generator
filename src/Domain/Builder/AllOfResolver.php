<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Schema\ResolvedSchema;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaType;
use MSSTC4PHP\DtoGenerator\Domain\Target\AllOfStrategy;

/**
 * Resolves the `allOf` of a class (spec §5.3): `extends` the one member that references a generated class, or `merge`
 * the properties of every member, nested compositions included.
 */
final class AllOfResolver
{
    private ClassLookup $classes;

    private AllOfStrategy $strategy;

    /** @var array<string, array{Composition, NamedClass|null}> ancestors resolved while collecting requirements */
    private array $ancestors = [];

    private Merges $merges;

    /**
     * @param Merges $merges the class schemas to merge, as a member redeclares a property PHP cannot
     */
    public function __construct(ClassLookup $classes, AllOfStrategy $strategy, Merges $merges)
    {
        $this->classes = $classes;
        $this->strategy = $strategy;
        $this->merges = $merges;
    }

    public function compose(Schema $schema, Diagnostics $diagnostics): Composition
    {
        [$composition, $parent] = $this->resolve($schema, $diagnostics);

        if (!$parent instanceof NamedClass) {
            return $composition;
        }

        [$inherited, $required] = $this->requirements($composition, $parent);

        return $composition->withInheritedRequirements($inherited)->withRequired($required);
    }

    /**
     * The composition and the schema and class it extends.
     *
     * @return array{Composition, NamedClass|null}
     */
    private function resolve(Schema $schema, Diagnostics $diagnostics): array
    {
        $members = $schema->allOf();
        if ($members === []) {
            return [Composition::of($schema), null];
        }

        $parents = [];
        foreach ($members as $index => $member) {
            $target = $this->classes->target($member);
            $behind = $target instanceof ResolvedSchema ? $this->classes->behind($target) : null;
            if ($behind instanceof NamedClass) {
                $parents[$index] = $behind;
            }
        }

        $index = $this->parentIndex($schema, $parents, $diagnostics);
        $itself = null;
        if ($index !== null && $parents[$index]->isGeneratedFrom($schema)) {
            $diagnostics->error(sprintf('Class %s extends itself.', $parents[$index]->name()->fqcn()), $members[$index]->location());
            $itself = $index;
            $index = null;
        }

        $key = $schema->location()->toString();
        $parts = new CompositionParts();
        // Requirements beside the parent's $ref, or beside the $refs of the aliases and wrappers before it, name inherited
        // properties: inheritedRequirements() collects them.
        if ($index !== null) {
            $parts->requireFrom($members[$index]);
            foreach ($parents[$index]->via() as $passed) {
                $parts->requireFrom($passed);
            }
        }

        foreach ($members as $at => $member) {
            if ($at !== $index && $at !== $itself) {
                $this->flatten($member, true, [$key => $key], $parts, $diagnostics);
            }
        }

        $parent = $index === null ? null : $parents[$index];

        return [$parts->finish($schema, $parent instanceof NamedClass ? $parent->name() : null), $parent];
    }

    /**
     * The member the class extends, if any.
     *
     * @param array<int, NamedClass> $parents the members that reference a generated class, by index
     */
    private function parentIndex(Schema $schema, array $parents, Diagnostics $diagnostics): ?int
    {
        $strategy = $this->strategy;
        $redeclared = $this->merges->of($schema->location());
        if ($schema->extensions()->has('x-php-all-of')) {
            $at = $schema->location()->child('x-php-all-of');
            $value = $schema->extensions()->get('x-php-all-of');
            $explicit = is_string($value) ? AllOfStrategy::tryFrom($value) : null;
            if (!$explicit instanceof AllOfStrategy) {
                $diagnostics->error('"x-php-all-of" must be "extends" or "merge".', $at);
            } elseif ($explicit->value() === AllOfStrategy::EXTENDS && count($parents) !== 1) {
                $diagnostics->warning('"x-php-all-of: extends" needs exactly one $ref to a generated class, so the members are merged.', $at);
            } else {
                $strategy = $explicit;
            }
        } elseif (count($parents) === 1 && $redeclared instanceof RedeclaredProperty) {
            $diagnostics->warning(
                sprintf(
                    'Property "%s" is declared again with a type PHP lets no subclass declare, so the members are merged instead of extending %s; "x-php-all-of: merge" says so, "extends" keeps the error.',
                    $redeclared->wireName(),
                    $parents[array_key_first($parents)]->name()->fqcn(),
                ),
                $redeclared->location(),
            );

            return null;
        }

        return count($parents) === 1 && $strategy->value() === AllOfStrategy::EXTENDS ? array_key_first($parents) : null;
    }

    /**
     * What the ancestors' `required` asks of the class: the properties an ancestor declares optional that the class, or
     * an ancestor between, requires (Hierarchy keeps those the class inherits), and every name the chain requires, own
     * properties an ancestor names without declaring them among them.
     *
     * @return array{array<array-key, non-empty-list<Schema>>, array<array-key, string>} the declarations of the nearest
     *                                                                                   ancestor by wire name, and the
     *                                                                                   required wire names
     */
    private function requirements(Composition $composition, NamedClass $parent): array
    {
        $own = $this->propertyNames($composition);
        $required = $composition->required();
        $declarations = [];
        $alreadyRequired = [];
        $seen = [];
        for ($ancestor = $parent; $ancestor instanceof NamedClass && !isset($seen[$ancestor->name()->fqcn()]); $ancestor = $next) {
            $seen[$ancestor->name()->fqcn()] = $ancestor;
            [$ancestry, $next] = $this->ancestor($ancestor->schema());
            $required += $ancestry->required();
            $nearer = $declarations;
            foreach ($ancestry->propertySources() as [$wireName, $declaration]) {
                if (!isset($nearer[$wireName])) {
                    $declarations[$wireName][] = $declaration;
                    // Only an ancestor's own properties can be required there; extending drops the rest, as it does here.
                    if ($ancestry->isRequired($wireName)) {
                        $alreadyRequired[$wireName] = $wireName;
                    }
                }
            }
        }

        return [array_diff_key(array_intersect_key($declarations, $required), $own, $alreadyRequired), $required];
    }

    /**
     * The composition of an ancestor, whose own problems are reported when its class is built.
     *
     * @return array{Composition, NamedClass|null}
     */
    private function ancestor(ResolvedSchema $schema): array
    {
        $key = $schema->location()->toString();
        $this->ancestors[$key] ??= $this->resolve($schema->schema(), new Diagnostics());

        return $this->ancestors[$key];
    }

    /**
     * @return array<string, string>
     */
    private function propertyNames(Composition $composition): array
    {
        $names = [];
        foreach ($composition->parts() as $part) {
            foreach ($part->propertyNames() as $wireName) {
                $names[$wireName] = $wireName;
            }
        }

        return $names;
    }

    /**
     * Adds a member, after the members of its own `allOf`, to the parts of the class.
     *
     * @param bool $inside whether the member is written inside the class schema, not reached through a $ref
     * @param array<string, string> $merging locations whose members are being merged, to stop loops
     * @param Schema|null $origin the member written in the class; errors found through aliases point there
     */
    private function flatten(Schema $member, bool $inside, array $merging, CompositionParts $parts, Diagnostics $diagnostics, ?Schema $origin = null): void
    {
        $origin ??= $member;
        $schema = $member;
        if ($member->ref() !== null) {
            $parts->requireFrom($member);
            $target = $this->classes->target($member);
            // An unresolved $ref was already reported while loading.
            if (!$target instanceof ResolvedSchema) {
                return;
            }

            $schema = $target->schema();
            $inside = false;
            if ($schema->ref() !== null) {
                $this->flattenAlias($origin, $schema, $inside, $merging, $parts, $diagnostics);

                return;
            }
        }

        if (!$this->isObject($schema)) {
            $diagnostics->error(
                $schema->oneOf() === [] && $schema->anyOf() === []
                    ? 'An allOf member of a class must be an object schema.'
                    : 'An allOf member of a class must be an object schema, not a oneOf or anyOf: no class adds properties to each variant.',
                $origin->location(),
            );

            return;
        }

        $key = $schema->location()->toString();
        if (isset($merging[$key])) {
            $diagnostics->error('The allOf chain loops back to a schema it is already merging.', $origin->location());

            return;
        }

        $merging[$key] = $key;
        foreach ($schema->allOf() as $nested) {
            $this->flatten($nested, $inside, $merging, $parts, $diagnostics);
        }

        $parts->add($schema, $inside);
    }

    /**
     * An alias (`{$ref: Pet}`) merges what it names; flatten() takes the requirements beside its `$ref`.
     *
     * @param Schema $origin the member written in the class
     * @param bool $inside false: the alias was reached through a $ref
     * @param array<string, string> $merging
     */
    private function flattenAlias(Schema $origin, Schema $alias, bool $inside, array $merging, CompositionParts $parts, Diagnostics $diagnostics): void
    {
        $key = $alias->location()->toString();
        if (isset($merging[$key])) {
            $diagnostics->error('The allOf chain loops back to a schema it is already merging.', $origin->location());

            return;
        }

        $merging[$key] = $key;
        $this->flatten($alias, $inside, $merging, $parts, $diagnostics, $origin);
    }

    /**
     * An object, or a schema that only constrains one (`required`, a description).
     */
    private function isObject(Schema $schema): bool
    {
        $types = $schema->nonNullTypes();

        return ($types === [] || $types === [SchemaType::from(SchemaType::OBJECT)])
            && !SchemaShape::isEnum($schema)
            && (!SchemaShape::hasUnion($schema) || SchemaShape::isClass($schema));
    }
}
