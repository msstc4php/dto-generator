<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Schema\ReferenceUse;
use MSSTC4PHP\DtoGenerator\Domain\Schema\ResolvedSchema;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaGraph;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaType;
use MSSTC4PHP\DtoGenerator\Domain\Target\AllOfStrategy;

/**
 * Resolves the `allOf` of a class (spec §5.3): `extends` the one member that references a generated class, or `merge`
 * the properties of every member, nested compositions included.
 */
final class AllOfResolver
{
    private SchemaGraph $graph;

    private Declarations $declarations;

    private AllOfStrategy $strategy;

    public function __construct(SchemaGraph $graph, Declarations $declarations, AllOfStrategy $strategy)
    {
        $this->graph = $graph;
        $this->declarations = $declarations;
        $this->strategy = $strategy;
    }

    public function compose(Schema $schema, Diagnostics $diagnostics): Composition
    {
        $members = $schema->allOf();
        if ($members === []) {
            return Composition::of($schema);
        }

        $strategy = $this->strategy($schema, $diagnostics);
        $classes = [];
        foreach ($members as $index => $member) {
            $class = $this->referencedClass($member);
            if ($class instanceof ClassName) {
                $classes[$index] = $class;
            }
        }

        $parent = count($classes) === 1 && $strategy->value() === AllOfStrategy::EXTENDS ? $classes[array_key_first($classes)] : null;
        $parts = [];
        $own = [];
        $required = [];
        $key = $schema->location()->toString();
        foreach ($members as $index => $member) {
            if (!$parent instanceof ClassName || !isset($classes[$index])) {
                $this->flatten($member, true, [$key => $key], $parts, $own, $required, $diagnostics);
            }
        }

        $parts[] = $schema;
        $own[] = $schema;
        $required += Composition::required($schema);

        return new Composition($parent, $parts, $own, $required);
    }

    private function strategy(Schema $schema, Diagnostics $diagnostics): AllOfStrategy
    {
        if (!$schema->extensions()->has('x-php-all-of')) {
            return $this->strategy;
        }

        $value = $schema->extensions()->get('x-php-all-of');
        $strategy = is_string($value) ? AllOfStrategy::tryFrom($value) : null;
        if ($strategy instanceof AllOfStrategy) {
            return $strategy;
        }

        $diagnostics->error('"x-php-all-of" must be "extends" or "merge".', $schema->location()->child('x-php-all-of'));

        return $this->strategy;
    }

    private function referencedClass(Schema $member): ?ClassName
    {
        $target = $this->target($member);

        return $target instanceof ResolvedSchema ? $this->declarations->classAt($target->location()->toString()) : null;
    }

    private function target(Schema $member): ?ResolvedSchema
    {
        $ref = $member->ref();

        return $ref === null ? null : $this->graph->resolve(new ReferenceUse($ref, $member->location()));
    }

    /**
     * Adds a member, after the members of its own `allOf`, to the parts of the class.
     *
     * @param bool $inside whether the member is written inside the class schema, not reached through a $ref
     * @param array<string, string> $merging locations whose members are being merged, to stop loops
     * @param list<Schema> $parts
     * @param list<Schema> $own
     * @param array<string, string> $required
     */
    private function flatten(Schema $member, bool $inside, array $merging, array &$parts, array &$own, array &$required, Diagnostics $diagnostics): void
    {
        $schema = $member;
        if ($member->ref() !== null) {
            $inside = false;
            $target = $this->target($member);
            // An unresolved $ref was already reported while loading.
            if (!$target instanceof ResolvedSchema) {
                return;
            }

            $schema = $target->schema();
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
            $this->flatten($nested, $inside, $merging, $parts, $own, $required, $diagnostics);
        }

        $parts[] = $schema;
        if ($inside) {
            $own[] = $schema;
        }

        $required += Composition::required($schema);
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
