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
        $chain = [$schema];
        for ($current = $schema; $current->ref() !== null; $current = $next) {
            $target = $this->resolve(new ReferenceUse($current->ref(), $current->location()));
            if (!$target instanceof ResolvedSchema || $this->listed($target->schema(), $chain)) {
                break;
            }

            $next = $target->schema();
            $chain[] = $next;
        }

        return $chain;
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
