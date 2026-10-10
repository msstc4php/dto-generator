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
 *
 * @api
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
     * The schema at the end of a `$ref` chain; the schema itself when it has no `$ref` or when the reference does not
     * resolve; at a cycle, the schema where it closes. Keywords beside a `$ref` (`{$ref: Email, maxLength: 64}`) stay
     * on the schema that has them: a caller that needs both reads that schema first, then the resolved one.
     */
    public function resolve(Schema $schema): Schema
    {
        return $this->walk($schema)[1];
    }

    /**
     * The schema and every schema its `$ref` chain passes through, until a reference does not resolve or leads back to
     * a schema already listed (no schema repeats). Each may carry keywords beside its `$ref`, and a value must satisfy
     * all of them.
     *
     * @return non-empty-list<Schema>
     */
    public function chain(Schema $schema): array
    {
        return $this->walk($schema)[0];
    }

    /**
     * @return array{non-empty-list<Schema>, Schema} the chain, and the schema resolve() returns
     */
    private function walk(Schema $schema): array
    {
        if (!$this->graph instanceof SchemaGraph) {
            return [[$schema], $schema];
        }

        $chain = $this->graph->chain($schema);
        $last = $chain[count($chain) - 1];
        // A chain stops at a reference that does not resolve or that closes a cycle; only the latter has a target.
        $target = $last->ref() === null ? null : $this->graph->resolve(new ReferenceUse($last->ref(), $last->location()));

        return [$chain, $target instanceof ResolvedSchema ? $target->schema() : $last];
    }
}
