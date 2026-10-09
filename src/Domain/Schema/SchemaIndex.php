<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Schema;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;

/**
 * Every schema of a graph by location, subschemas included, so a model node finds the schema it was built from.
 *
 * @api
 */
final class SchemaIndex
{
    /** @var array<string, Schema> */
    private array $schemas = [];

    private function __construct()
    {
    }

    public static function of(SchemaGraph $graph): self
    {
        $index = new self();
        foreach ($graph->all() as $resolved) {
            $index->add($resolved->schema());
        }

        return $index;
    }

    public function get(SchemaLocation $location): ?Schema
    {
        return $this->schemas[$location->toString()] ?? null;
    }

    /**
     * A schema a model node was built from, which the graph holds by construction.
     */
    public function require(SchemaLocation $location): Schema
    {
        $schema = $this->get($location);
        if (!$schema instanceof Schema) {
            throw new InvalidModel(sprintf('No schema at %s.', $location->toString()));
        }

        return $schema;
    }

    private function add(Schema $schema): void
    {
        // A subschema that a $ref also names is a graph entry of its own; both are the same schema.
        $this->schemas[$schema->location()->toString()] = $schema;
        foreach ($schema->propertyNames() as $name) {
            $this->add($schema->requireProperty($name));
        }

        $items = $schema->items();
        $additional = $schema->additionalProperties();
        foreach (array_merge($items instanceof Schema ? [$items] : [], $additional instanceof Schema ? [$additional] : [], $schema->allOf(), $schema->oneOf(), $schema->anyOf()) as $subschema) {
            $this->add($subschema);
        }
    }
}
