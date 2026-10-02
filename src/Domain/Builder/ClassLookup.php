<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Schema\ReferenceUse;
use MSSTC4PHP\DtoGenerator\Domain\Schema\ResolvedSchema;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaGraph;

/**
 * The generated class a `$ref` stands for, also through aliases (`{$ref: Pet}`) and `allOf: [$ref]` wrappers that only
 * annotate a reference.
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
        $via = [];
        for ($target = $named; !isset($seen[$target->location()->toString()]); $target = $next) {
            $key = $target->location()->toString();
            $seen[$key] = $key;
            $class = $this->declarations->classAt($key);
            if ($class instanceof ClassName) {
                return new NamedClass($target, $class, $via);
            }

            $passage = $this->passage($target->schema());
            $next = $passage instanceof Schema ? $this->target($passage) : null;
            if (!$next instanceof ResolvedSchema) {
                return null;
            }

            $via[] = $passage;
        }

        return null;
    }

    /**
     * The schema whose `$ref` leads on: an alias itself, or the one `$ref` member of an `allOf` that is no class of its
     * own. Requirements written beside that `$ref` name properties of the class behind it.
     */
    private function passage(Schema $schema): ?Schema
    {
        if ($schema->ref() !== null) {
            return $schema;
        }

        $references = array_values(array_filter($schema->allOf(), static fn (Schema $member): bool => $member->ref() !== null));

        return count($references) === 1 && !SchemaShape::isClass($schema) ? $references[0] : null;
    }
}
