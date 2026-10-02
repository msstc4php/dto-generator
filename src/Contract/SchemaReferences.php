<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Contract;

use MSSTC4PHP\DtoGenerator\Domain\Schema\ReferenceUse;
use MSSTC4PHP\DtoGenerator\Domain\Schema\ResolvedSchema;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaGraph;

/**
 * Lets an enricher read the schema a `$ref` points to: a property `{$ref: Email}` keeps its keywords, like `format`,
 * in Email.
 */
final class SchemaReferences
{
    private ?SchemaGraph $graph;

    public function __construct(?SchemaGraph $graph)
    {
        $this->graph = $graph;
    }

    /**
     * For contexts built without a graph: every schema stands for itself.
     */
    public static function none(): self
    {
        return new self(null);
    }

    /**
     * The schema at the end of a `$ref` chain; the schema itself when it has no `$ref`, when the reference does not
     * resolve, or at a cycle.
     */
    public function resolve(Schema $schema): Schema
    {
        $seen = [];
        $current = $schema;
        while ($this->graph instanceof SchemaGraph && $current->ref() !== null) {
            $key = $current->location()->toString();
            if (isset($seen[$key])) {
                return $current;
            }

            $seen[$key] = true;
            $target = $this->graph->resolve(new ReferenceUse($current->ref(), $current->location()));
            if (!$target instanceof ResolvedSchema) {
                return $current;
            }

            $current = $target->schema();
        }

        return $current;
    }
}
