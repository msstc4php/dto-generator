<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Schema;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;
use MSSTC4PHP\DtoGenerator\Domain\Shared\DefaultValue;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;

/**
 * A parsed JSON Schema 2020-12 node. `$ref` is kept verbatim; resolution happens outside the domain.
 *
 * @phpstan-import-type JsonValue from Json
 */
final class Schema
{
    public const STRUCTURAL_KEYWORDS = [
        'type', '$ref', 'format', 'description', 'deprecated', 'default', 'enum', 'properties', 'required',
        'items', 'additionalProperties', 'allOf', 'oneOf', 'anyOf', 'discriminator',
    ];

    private SchemaLocation $location;

    /** @var list<SchemaType> */
    private array $types;

    private ?string $ref;

    private ?string $format;

    private ?string $description;

    private bool $deprecated;

    private ?DefaultValue $default;

    /** @var non-empty-list<JsonValue>|null */
    private ?array $enum;

    /**
     * Keys are property names; PHP turns numeric ones into ints, hence `int|string`.
     *
     * @var array<int|string, Schema>
     */
    private array $properties;

    /** @var list<string> */
    private array $required;

    private ?Schema $items;

    /** @var bool|Schema|null */
    private $additionalProperties;

    /** @var list<Schema> */
    private array $allOf;

    /** @var list<Schema> */
    private array $oneOf;

    /** @var list<Schema> */
    private array $anyOf;

    private ?Discriminator $discriminator;

    /** @var array<string, JsonValue> */
    private array $keywords;

    private Extensions $extensions;

    /**
     * @param list<SchemaType> $types
     * @param list<JsonValue>|null $enum
     * @param array<int|string, Schema> $properties
     * @param list<string> $required
     * @param bool|Schema|null $additionalProperties
     * @param list<Schema> $allOf
     * @param list<Schema> $oneOf
     * @param list<Schema> $anyOf
     * @param array<string, JsonValue> $keywords validation keywords without a dedicated accessor
     */
    public function __construct(
        SchemaLocation $location,
        array $types,
        ?string $ref,
        ?string $format,
        ?string $description,
        bool $deprecated,
        ?DefaultValue $default,
        ?array $enum,
        array $properties,
        array $required,
        ?Schema $items,
        $additionalProperties,
        array $allOf,
        array $oneOf,
        array $anyOf,
        ?Discriminator $discriminator,
        array $keywords,
        Extensions $extensions
    ) {
        $seenTypes = [];
        foreach ($types as $type) {
            if (isset($seenTypes[$type->value()])) {
                throw new InvalidModel(sprintf('Schema %s repeats type "%s".', $location->toString(), $type->value()));
            }
            $seenTypes[$type->value()] = true;
        }

        if ($enum === []) {
            throw new InvalidModel(sprintf('Schema %s has an empty "enum".', $location->toString()));
        }

        if (count(array_unique($required)) !== count($required)) {
            throw new InvalidModel(sprintf('Schema %s repeats a name in "required".', $location->toString()));
        }

        foreach (array_keys($keywords) as $keyword) {
            if (in_array($keyword, self::STRUCTURAL_KEYWORDS, true) || strncmp($keyword, 'x-', 2) === 0) {
                throw new InvalidModel(sprintf('Schema %s: "%s" has a dedicated field and cannot be a generic keyword.', $location->toString(), $keyword));
            }
        }

        $this->location = $location;
        $this->types = $types;
        $this->ref = $ref;
        $this->format = $format;
        $this->description = $description;
        $this->deprecated = $deprecated;
        $this->default = $default;
        $this->enum = $enum;
        $this->properties = $properties;
        $this->required = $required;
        $this->items = $items;
        $this->additionalProperties = $additionalProperties;
        $this->allOf = $allOf;
        $this->oneOf = $oneOf;
        $this->anyOf = $anyOf;
        $this->discriminator = $discriminator;
        $this->keywords = $keywords;
        $this->extensions = $extensions;
    }

    public function location(): SchemaLocation
    {
        return $this->location;
    }

    /**
     * @return list<SchemaType>
     */
    public function types(): array
    {
        return $this->types;
    }

    public function hasType(SchemaType $type): bool
    {
        return in_array($type, $this->types, true);
    }

    public function isNullable(): bool
    {
        return $this->hasType(SchemaType::from(SchemaType::NULL));
    }

    /**
     * @return list<SchemaType>
     */
    public function nonNullTypes(): array
    {
        $null = SchemaType::from(SchemaType::NULL);

        return array_values(array_filter($this->types, static fn (SchemaType $type): bool => $type !== $null));
    }

    public function ref(): ?string
    {
        return $this->ref;
    }

    public function format(): ?string
    {
        return $this->format;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    public function isDeprecated(): bool
    {
        return $this->deprecated;
    }

    public function default(): ?DefaultValue
    {
        return $this->default;
    }

    /**
     * @return non-empty-list<JsonValue>|null
     */
    public function enum(): ?array
    {
        return $this->enum;
    }

    /**
     * @return list<string>
     */
    public function propertyNames(): array
    {
        return array_map('strval', array_keys($this->properties));
    }

    public function property(string $name): ?self
    {
        return $this->properties[$name] ?? null;
    }

    public function isRequired(string $name): bool
    {
        return in_array($name, $this->required, true);
    }

    /**
     * @return list<string>
     */
    public function required(): array
    {
        return $this->required;
    }

    public function items(): ?self
    {
        return $this->items;
    }

    /**
     * @return bool|Schema|null null when the keyword is absent
     */
    public function additionalProperties()
    {
        return $this->additionalProperties;
    }

    /**
     * @return list<Schema>
     */
    public function allOf(): array
    {
        return $this->allOf;
    }

    /**
     * @return list<Schema>
     */
    public function oneOf(): array
    {
        return $this->oneOf;
    }

    /**
     * @return list<Schema>
     */
    public function anyOf(): array
    {
        return $this->anyOf;
    }

    public function discriminator(): ?Discriminator
    {
        return $this->discriminator;
    }

    public function hasKeyword(string $name): bool
    {
        return array_key_exists($name, $this->keywords);
    }

    /**
     * @return JsonValue
     */
    public function keyword(string $name)
    {
        if (!$this->hasKeyword($name)) {
            throw new InvalidModel(sprintf('Keyword "%s" is not set on schema %s.', $name, $this->location->toString()));
        }

        return $this->keywords[$name];
    }

    /**
     * @return array<string, JsonValue>
     */
    public function keywords(): array
    {
        return $this->keywords;
    }

    public function extensions(): Extensions
    {
        return $this->extensions;
    }
}
