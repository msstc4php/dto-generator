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
     * The schema at the end of a `$ref` chain; the schema itself when it has no `$ref` or when the reference does not
     * resolve; at a cycle, the schema where it closes. Keywords beside a `$ref` (`{$ref: Email, maxLength: 64}`) stay
     * on the schema that has them: a caller that needs both reads that schema first, then the resolved one.
     */
    public function resolve(Schema $schema): Schema
    {
        return $this->walk($schema)[1];
    }

    /**
     * The schema and every schema its `$ref` chain passes through, up to the one resolve() returns. Each may carry
     * keywords beside its `$ref`, and a value must satisfy all of them.
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
        $chain = [$schema];
        $seen = [];
        $current = $schema;
        while ($this->graph instanceof SchemaGraph && $current->ref() !== null) {
            $seen[] = $current->location()->toString();
            $target = $this->graph->resolve(new ReferenceUse($current->ref(), $current->location()));
            if (!$target instanceof ResolvedSchema) {
                break;
            }

            if (in_array($target->schema()->location()->toString(), $seen, true)) {
                return [$chain, $target->schema()];
            }

            $current = $target->schema();
            $chain[] = $current;
        }

        return [$chain, $current];
    }
}
