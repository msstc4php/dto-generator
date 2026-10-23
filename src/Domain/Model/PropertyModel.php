<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Model;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use MSSTC4PHP\DtoGenerator\Domain\Shared\DefaultValue;

/**
 * @api
 */
final class PropertyModel
{
    private string $name;

    private string $wireName;

    private TypeModel $type;

    private bool $required;

    private ?DefaultValue $default;

    private DocModel $doc;

    private SchemaLocation $source;

    /** @var list<AttributeModel> */
    private array $attributes;

    private bool $additionalProperties;

    /**
     * @param string $wireName the name in the schema, kept for serialization
     * @param list<AttributeModel> $attributes
     * @param bool $additionalProperties whether it collects the properties the schema does not declare
     */
    public function __construct(
        string $name,
        string $wireName,
        TypeModel $type,
        bool $required,
        ?DefaultValue $default,
        DocModel $doc,
        SchemaLocation $source,
        array $attributes = [],
        bool $additionalProperties = false
    ) {
        if (!Identifier::isValid($name) || $name === 'this' || Identifier::isSuperglobal($name)) {
            throw new InvalidModel(sprintf('"%s" is not a usable PHP property name (%s).', $name, $source->toString()));
        }

        if ($wireName === '') {
            throw new InvalidModel(sprintf('Property "%s" has an empty wire name (%s).', $name, $source->toString()));
        }

        // The constructor puts parameters without a default first, so "required" must mean exactly that.
        if ($required && $default instanceof DefaultValue) {
            throw new InvalidModel(sprintf('Property "%s" is required, so it cannot have a default (%s).', $name, $source->toString()));
        }

        if (!$required && !$default instanceof DefaultValue) {
            throw new InvalidModel(sprintf('Property "%s" is optional, so it needs a default (%s).', $name, $source->toString()));
        }

        $this->name = $name;
        $this->wireName = $wireName;
        $this->type = $type;
        $this->required = $required;
        $this->default = $default;
        $this->doc = $doc;
        $this->source = $source;
        $this->attributes = $attributes;
        $this->additionalProperties = $additionalProperties;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function wireName(): string
    {
        return $this->wireName;
    }

    public function type(): TypeModel
    {
        return $this->type;
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    public function default(): ?DefaultValue
    {
        return $this->default;
    }

    public function doc(): DocModel
    {
        return $this->doc;
    }

    public function source(): SchemaLocation
    {
        return $this->source;
    }

    /**
     * @return list<AttributeModel>
     */
    public function attributes(): array
    {
        return $this->attributes;
    }

    public function isNullable(): bool
    {
        return $this->type instanceof NullableType;
    }

    /**
     * Whether it collects the properties the schema does not declare (`properties` beside an `additionalProperties`
     * schema), rather than a declared property that may share its name.
     */
    public function isAdditionalProperties(): bool
    {
        return $this->additionalProperties;
    }

    /**
     * The same property with another type: a nullable one is optional, with the default it has or null; another one is
     * required unless a default other than null sets it.
     */
    public function withType(TypeModel $type): self
    {
        $required = !$type instanceof NullableType && (!$this->default instanceof DefaultValue || $this->default->value() === null);

        return new self(
            $this->name,
            $this->wireName,
            $type,
            $required,
            $required ? null : $this->default ?? new DefaultValue(null),
            $this->doc,
            $this->source,
            $this->attributes,
            $this->additionalProperties,
        );
    }

    public function withName(string $name): self
    {
        return new self(
            $name,
            $this->wireName,
            $this->type,
            $this->required,
            $this->default,
            $this->doc,
            $this->source,
            $this->attributes,
            $this->additionalProperties,
        );
    }

    public function withAddedAttributes(AttributeModel ...$attributes): self
    {
        return new self(
            $this->name,
            $this->wireName,
            $this->type,
            $this->required,
            $this->default,
            $this->doc,
            $this->source,
            array_merge($this->attributes, $attributes),
            $this->additionalProperties,
        );
    }
}
