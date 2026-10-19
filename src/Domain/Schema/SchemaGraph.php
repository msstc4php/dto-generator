<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Schema;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;

/**
 * Every schema a generation run needs, keyed by location: selected components and all their $ref targets.
 *
 * @api
 */
final class SchemaGraph
{
    /** @var array<string, ResolvedSchema> */
    private array $schemas = [];

    /** @var array<string, string> */
    private array $edges;

    /** @var array<string, VariantOrigin> by location key */
    private array $origins = [];

    /**
     * @param list<ResolvedSchema> $schemas
     * @param array<string, string> $edges {@see ReferenceUse::key()} → location key of the schema it resolved to
     */
    public function __construct(array $schemas, array $edges = [])
    {
        foreach ($schemas as $schema) {
            $key = $schema->location()->toString();
            if (isset($this->schemas[$key])) {
                throw new InvalidModel(sprintf('Schema %s is registered twice.', $key));
            }

            $this->schemas[$key] = $schema;
        }

        foreach ($edges as $use => $target) {
            if (!isset($this->schemas[$target])) {
                throw new InvalidModel(sprintf('Reference %s points to an unknown schema %s.', $use, $target));
            }
        }

        $this->edges = $edges;
    }

    /**
     * The graph with these schemas in place of those at their locations or beside them, and these references too.
     *
     * @param list<ResolvedSchema> $schemas
     * @param array<string, string> $edges {@see ReferenceUse::key()} → location key of the schema it resolves to
     * @param array<string, VariantOrigin> $origins location key → where a schema distributed from an allOf comes from
     */
    public function with(array $schemas, array $edges = [], array $origins = []): self
    {
        $given = [];
        foreach ($schemas as $schema) {
            $key = $schema->location()->toString();
            if (isset($given[$key])) {
                throw new InvalidModel(sprintf('Schema %s is given twice.', $key));
            }

            $given[$key] = $schema;
        }

        foreach (array_intersect_key($edges, $this->edges) as $use => $target) {
            if ($this->edges[$use] !== $target) {
                throw new InvalidModel(sprintf('Reference %s already points to %s.', $use, $this->edges[$use]));
            }
        }

        $all = [];
        foreach ($this->schemas as $key => $schema) {
            $all[] = $given[$key] ?? $schema;
        }

        foreach (array_diff_key($given, $this->schemas) as $schema) {
            $all[] = $schema;
        }

        $graph = new self($all, $edges + $this->edges);
        $graph->origins = $origins + $this->origins;

        return $graph;
    }

    public function originOf(SchemaLocation $location): ?VariantOrigin
    {
        return $this->origins[$location->toString()] ?? null;
    }

    public function get(SchemaLocation $location): ?ResolvedSchema
    {
        return $this->schemas[$location->toString()] ?? null;
    }

    /**
     * The schema a `$ref` resolved to while loading; null when it could not be resolved (already reported).
     */
    public function resolve(ReferenceUse $use): ?ResolvedSchema
    {
        $target = $this->edges[$use->key()] ?? null;

        return $target === null ? null : $this->schemas[$target];
    }

    /**
     * @return list<ResolvedSchema>
     */
    public function all(): array
    {
        return array_values($this->schemas);
    }

    /**
     * The schema and every schema its `$ref` chain passes through, until a reference does not resolve or leads back to
     * a schema already listed (no schema repeats).
     *
     * @return non-empty-list<Schema>
     */
    public function chain(Schema $schema): array
    {
        return $this->walk($schema)[0];
    }

    /**
     * The chain, and the schema its last reference leads back to when it closes a cycle.
     *
     * @return array{non-empty-list<Schema>, ?Schema}
     */
    public function walk(Schema $schema): array
    {
        $chain = [$schema];
        for ($current = $schema; $current->ref() !== null; $current = $next) {
            $target = $this->resolve(new ReferenceUse($current->ref(), $current->location()));
            if (!$target instanceof ResolvedSchema) {
                break;
            }

            $next = $target->schema();
            if ($this->listed($next, $chain)) {
                return [$chain, $next];
            }

            $chain[] = $next;
        }

        return [$chain, null];
    }

    /**
     * @param list<Schema> $chain
     */
    private function listed(Schema $schema, array $chain): bool
    {
        foreach ($chain as $listed) {
            if ($listed->location()->toString() === $schema->location()->toString()) {
                return true;
            }
        }

        return false;
    }
}
