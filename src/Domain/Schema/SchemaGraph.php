<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Schema;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;

/**
 * Every schema a generation run needs, keyed by location: selected components and all their $ref targets.
 */
final class SchemaGraph
{
    /** @var array<string, ResolvedSchema> */
    private array $schemas = [];

    /**
     * @param list<ResolvedSchema> $schemas
     */
    public function __construct(array $schemas)
    {
        foreach ($schemas as $schema) {
            $key = $schema->location()->toString();
            if (isset($this->schemas[$key])) {
                throw new InvalidModel(sprintf('Schema %s is registered twice.', $key));
            }

            $this->schemas[$key] = $schema;
        }
    }

    public function get(SchemaLocation $location): ?ResolvedSchema
    {
        return $this->schemas[$location->toString()] ?? null;
    }

    /**
     * Null for remote references and for targets that could not be loaded.
     */
    public function resolve(string $ref, SchemaLocation $from): ?ResolvedSchema
    {
        $target = Reference::target($ref, $from);

        return $target instanceof SchemaLocation ? $this->get($target) : null;
    }

    /**
     * @return list<ResolvedSchema>
     */
    public function all(): array
    {
        return array_values($this->schemas);
    }
}
