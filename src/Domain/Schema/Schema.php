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
    /** @var non-empty-list<non-empty-string> keywords with a dedicated accessor */
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

    /**
     * Keys are keyword names; PHP turns numeric ones into ints, hence `int|string`.
     *
     * @var array<int|string, JsonValue>
     */
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
     * @param array<int|string, JsonValue> $keywords validation keywords without a dedicated accessor
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
        $this->assertUniqueTypes($location, $types);
        $this->assertUsableEnum($location, $enum);
        $this->assertUniqueRequired($location, $required);
        $this->assertGenericKeywords($location, $keywords);

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
        foreach ($this->types as $type) {
            if ($type->isNull()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<SchemaType>
     */
    public function nonNullTypes(): array
    {
        return array_values(array_filter($this->types, static fn (SchemaType $type): bool => !$type->isNull()));
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
     * @return list<string>
     */
    public function keywordNames(): array
    {
        return array_map('strval', array_keys($this->keywords));
    }

    /**
     * Keys may be ints for numeric keyword names; use {@see keywordNames()} for strings.
     *
     * @return array<int|string, JsonValue>
     */
    public function keywords(): array
    {
        return $this->keywords;
    }

    public function extensions(): Extensions
    {
        return $this->extensions;
    }

    /**
     * Every `$ref` in this schema and its subschemas, plus discriminator mapping targets.
     *
     * @return list<ReferenceUse>
     */
    public function references(): array
    {
        $uses = $this->ref === null ? [] : [new ReferenceUse($this->ref, $this->location)];
        foreach ($this->subschemas() as $subschema) {
            foreach ($subschema->references() as $use) {
                $uses[] = $use;
            }
        }

        if ($this->discriminator instanceof Discriminator) {
            foreach ($this->discriminator->values() as $value) {
                $ref = $this->discriminator->refFor($value);
                if ($ref !== null) {
                    $uses[] = new ReferenceUse($ref, $this->location->child('discriminator', 'mapping', $value));
                }
            }
        }

        return $uses;
    }

    /**
     * @param list<SchemaType> $types
     */
    private function assertUniqueTypes(SchemaLocation $location, array $types): void
    {
        $seen = [];
        foreach ($types as $type) {
            if (isset($seen[$type->value()])) {
                throw new InvalidModel(sprintf('Schema %s repeats type "%s".', $location->toString(), $type->value()));
            }

            $seen[$type->value()] = true;
        }
    }

    /**
     * @param list<JsonValue>|null $enum
     *
     * @phpstan-assert non-empty-list<JsonValue>|null $enum
     */
    private function assertUsableEnum(SchemaLocation $location, ?array $enum): void
    {
        if ($enum === []) {
            throw new InvalidModel(sprintf('Schema %s has an empty "enum".', $location->toString()));
        }
    }

    /**
     * @param list<string> $required
     */
    private function assertUniqueRequired(SchemaLocation $location, array $required): void
    {
        if (count(array_unique($required)) !== count($required)) {
            throw new InvalidModel(sprintf('Schema %s repeats a name in "required".', $location->toString()));
        }
    }

    /**
     * @param array<int|string, JsonValue> $keywords
     */
    private function assertGenericKeywords(SchemaLocation $location, array $keywords): void
    {
        foreach (array_keys($keywords) as $keyword) {
            $keyword = (string) $keyword;
            if (in_array($keyword, self::STRUCTURAL_KEYWORDS, true) || Extensions::isExtensionKey($keyword)) {
                throw new InvalidModel(sprintf('Schema %s: "%s" has a dedicated field and cannot be a generic keyword.', $location->toString(), $keyword));
            }
        }
    }

    /**
     * @return list<Schema>
     */
    private function subschemas(): array
    {
        $subschemas = array_values($this->properties);
        if ($this->items instanceof self) {
            $subschemas[] = $this->items;
        }

        if ($this->additionalProperties instanceof self) {
            $subschemas[] = $this->additionalProperties;
        }

        return array_merge($subschemas, $this->allOf, $this->oneOf, $this->anyOf);
    }
}
