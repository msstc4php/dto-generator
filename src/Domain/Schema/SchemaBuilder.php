<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Schema;

use MSSTC4PHP\DtoGenerator\Domain\Shared\DefaultValue;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;

/**
 * Mutable assembly helper for {@see Schema}; the built schema is immutable.
 *
 * @phpstan-import-type JsonValue from Json
 */
final class SchemaBuilder
{
    private SchemaLocation $location;

    /** @var list<SchemaType> */
    private array $types = [];

    private ?string $ref = null;

    private ?string $format = null;

    private ?string $description = null;

    private bool $deprecated = false;

    private ?DefaultValue $default = null;

    /** @var list<JsonValue>|null */
    private ?array $enum = null;

    /** @var array<int|string, Schema> */
    private array $properties = [];

    /** @var list<string> */
    private array $required = [];

    private ?Schema $items = null;

    /** @var bool|Schema|null */
    private $additionalProperties;

    /** @var list<Schema> */
    private array $allOf = [];

    /** @var list<Schema> */
    private array $oneOf = [];

    /** @var list<Schema> */
    private array $anyOf = [];

    private ?Discriminator $discriminator = null;

    /** @var array<string, JsonValue> */
    private array $keywords = [];

    private Extensions $extensions;

    public function __construct(SchemaLocation $location)
    {
        $this->location = $location;
        $this->extensions = new Extensions();
    }

    public function types(SchemaType ...$types): self
    {
        $this->types = $types;

        return $this;
    }

    public function ref(string $ref): self
    {
        $this->ref = $ref;

        return $this;
    }

    public function format(string $format): self
    {
        $this->format = $format;

        return $this;
    }

    public function description(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function deprecated(bool $deprecated = true): self
    {
        $this->deprecated = $deprecated;

        return $this;
    }

    /**
     * @param JsonValue $value
     */
    public function defaultValue($value): self
    {
        $this->default = new DefaultValue($value);

        return $this;
    }

    /**
     * @param list<JsonValue> $values
     */
    public function enum(array $values): self
    {
        $this->enum = $values;

        return $this;
    }

    public function property(string $name, Schema $schema): self
    {
        $this->properties[$name] = $schema;

        return $this;
    }

    public function required(string ...$names): self
    {
        $this->required = $names;

        return $this;
    }

    public function items(Schema $items): self
    {
        $this->items = $items;

        return $this;
    }

    /**
     * @param bool|Schema $additionalProperties
     */
    public function additionalProperties($additionalProperties): self
    {
        $this->additionalProperties = $additionalProperties;

        return $this;
    }

    public function allOf(Schema ...$schemas): self
    {
        $this->allOf = $schemas;

        return $this;
    }

    public function oneOf(Schema ...$schemas): self
    {
        $this->oneOf = $schemas;

        return $this;
    }

    public function anyOf(Schema ...$schemas): self
    {
        $this->anyOf = $schemas;

        return $this;
    }

    public function discriminator(Discriminator $discriminator): self
    {
        $this->discriminator = $discriminator;

        return $this;
    }

    /**
     * @param JsonValue $value
     */
    public function keyword(string $name, $value): self
    {
        $this->keywords[$name] = $value;

        return $this;
    }

    public function extensions(Extensions $extensions): self
    {
        $this->extensions = $extensions;

        return $this;
    }

    public function build(): Schema
    {
        return new Schema(
            $this->location,
            $this->types,
            $this->ref,
            $this->format,
            $this->description,
            $this->deprecated,
            $this->default,
            $this->enum,
            $this->properties,
            $this->required,
            $this->items,
            $this->additionalProperties,
            $this->allOf,
            $this->oneOf,
            $this->anyOf,
            $this->discriminator,
            $this->keywords,
            $this->extensions,
        );
    }
}
