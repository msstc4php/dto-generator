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

    /** @var array<string, array{Composition, NamedClass|null}> ancestors resolved while checking requirements */
    private array $ancestors = [];

    public function __construct(ClassLookup $classes, AllOfStrategy $strategy)
    {
        $this->classes = $classes;
        $this->strategy = $strategy;
    }

    public function compose(Schema $schema, Diagnostics $diagnostics): Composition
    {
        [$composition, $parent] = $this->resolve($schema, $diagnostics);
        if ($parent instanceof NamedClass) {
            $this->warnAboutInheritedRequirements($schema, $composition, $parent, $diagnostics);
        }

        return $composition;
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
        $key = $schema->location()->toString();
        $parts = new CompositionParts();
        foreach ($members as $at => $member) {
            if ($at !== $index) {
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
        }

        return count($parents) === 1 && $strategy->value() === AllOfStrategy::EXTENDS ? array_key_first($parents) : null;
    }

    /**
     * A subclass cannot make an inherited property required, so a requirement on one is lost under extends.
     */
    private function warnAboutInheritedRequirements(Schema $schema, Composition $composition, NamedClass $parent, Diagnostics $diagnostics): void
    {
        $own = $this->propertyNames($composition);
        $inherited = [];
        $alreadyRequired = [];
        $seen = [];
        for ($ancestor = $parent; $ancestor instanceof NamedClass && !isset($seen[$ancestor->name()->fqcn()]); $ancestor = $next) {
            $seen[$ancestor->name()->fqcn()] = $ancestor;
            [$ancestry, $next] = $this->ancestor($ancestor->schema());
            $inherited += $this->propertyNames($ancestry);
            $alreadyRequired += $ancestry->required();
        }

        foreach ($composition->required() as $wireName) {
            if (!isset($own[$wireName]) && !isset($alreadyRequired[$wireName]) && isset($inherited[$wireName])) {
                $diagnostics->warning(
                    sprintf(
                        'Required property "%s" belongs to the parent %s, where extending cannot make it required; use "x-php-all-of: merge" to require it.',
                        $wireName,
                        $parent->name()->fqcn(),
                    ),
                    $schema->location(),
                );
            }
        }
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
     */
    private function flatten(Schema $member, bool $inside, array $merging, CompositionParts $parts, Diagnostics $diagnostics): void
    {
        $schema = $member;
        if ($member->ref() !== null) {
            $target = $this->classes->target($member);
            // An unresolved $ref was already reported while loading.
            if (!$target instanceof ResolvedSchema) {
                return;
            }

            $schema = $target->schema();
            $inside = false;
        }

        if (!$this->isObject($schema)) {
            $diagnostics->error('An allOf member of a class must be an object schema.', $member->location());

            return;
        }

        $key = $schema->location()->toString();
        if (isset($merging[$key])) {
            $diagnostics->error('The allOf chain loops back to a schema it is already merging.', $member->location());

            return;
        }

        $merging[$key] = $key;
        foreach ($schema->allOf() as $nested) {
            $this->flatten($nested, $inside, $merging, $parts, $diagnostics);
        }

        $parts->add($schema, $inside);
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
