<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Schema\ReferenceUse;
use MSSTC4PHP\DtoGenerator\Domain\Schema\ResolvedSchema;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaGraph;

/**
 * The generated class a `$ref` stands for, also through `allOf: [$ref]` wrappers that only annotate a reference.
 */
final class ClassLookup
{
    private SchemaGraph $graph;

    private Declarations $declarations;

    public function __construct(SchemaGraph $graph, Declarations $declarations)
    {
        $this->graph = $graph;
        $this->declarations = $declarations;
    }

    /**
     * The schema a member's `$ref` names; null without a `$ref` or when it did not resolve (reported while loading).
     */
    public function target(Schema $member): ?ResolvedSchema
    {
        $ref = $member->ref();

        return $ref === null ? null : $this->graph->resolve(new ReferenceUse($ref, $member->location()));
    }

    public function classBehind(ResolvedSchema $named): ?ClassName
    {
        $behind = $this->behind($named);

        return $behind instanceof NamedClass ? $behind->name() : null;
    }

    /**
     * The class behind a `$ref` and the schema it is generated from.
     */
    public function behind(ResolvedSchema $named): ?NamedClass
    {
        $seen = [];
        for ($target = $named; $target instanceof ResolvedSchema && !isset($seen[$target->location()->toString()]); $target = $this->unwrap($target->schema())) {
            $key = $target->location()->toString();
            $seen[$key] = $key;
            $class = $this->declarations->classAt($key);
            if ($class instanceof ClassName) {
                return new NamedClass($target, $class);
            }
        }

        return null;
    }

    /**
     * The target of the one `$ref` member of an `allOf` that is no class of its own.
     */
    private function unwrap(Schema $schema): ?ResolvedSchema
    {
        $references = array_values(array_filter($schema->allOf(), static fn (Schema $member): bool => $member->ref() !== null));

        return count($references) === 1 && !SchemaShape::isClass($schema) ? $this->target($references[0]) : null;
    }
}
