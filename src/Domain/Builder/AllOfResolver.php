<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
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

    public function __construct(ClassLookup $classes, AllOfStrategy $strategy)
    {
        $this->classes = $classes;
        $this->strategy = $strategy;
    }

    public function compose(Schema $schema, Diagnostics $diagnostics): Composition
    {
        $members = $schema->allOf();
        if ($members === []) {
            return Composition::of($schema);
        }

        $parents = [];
        foreach ($members as $index => $member) {
            $target = $this->classes->target($member);
            $class = $target instanceof ResolvedSchema ? $this->classes->classBehind($target) : null;
            if ($class instanceof ClassName) {
                $parents[$index] = $class;
            }
        }

        $parent = $this->parent($schema, $parents, $diagnostics);
        $key = $schema->location()->toString();
        $parts = new CompositionParts();
        foreach ($members as $index => $member) {
            if (!$parent instanceof ClassName || !isset($parents[$index])) {
                $this->flatten($member, true, [$key => $key], $parts, $diagnostics);
            }
        }

        $composition = $parts->finish($schema, $parent);
        if ($parent instanceof ClassName) {
            $this->warnAboutInheritedRequirements($schema, $composition, $parent, $diagnostics);
        }

        return $composition;
    }

    /**
     * @param array<int, ClassName> $parents the members that reference a generated class, by index
     */
    private function parent(Schema $schema, array $parents, Diagnostics $diagnostics): ?ClassName
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

        return count($parents) === 1 && $strategy->value() === AllOfStrategy::EXTENDS ? $parents[array_key_first($parents)] : null;
    }

    /**
     * A subclass cannot make an inherited property required, so a requirement on one is lost under extends.
     */
    private function warnAboutInheritedRequirements(Schema $schema, Composition $composition, ClassName $parent, Diagnostics $diagnostics): void
    {
        $own = [];
        foreach ($composition->parts() as $part) {
            foreach ($part->propertyNames() as $wireName) {
                $own[$wireName] = $wireName;
            }
        }

        foreach ($composition->required() as $wireName) {
            if (!isset($own[$wireName])) {
                $diagnostics->warning(
                    sprintf(
                        'Required property "%s" belongs to the parent %s, where extending cannot make it required; use "x-php-all-of: merge" to require it.',
                        $wireName,
                        $parent->fqcn(),
                    ),
                    $schema->location(),
                );
            }
        }
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
