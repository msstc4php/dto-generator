<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Model;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use MSSTC4PHP\DtoGenerator\Domain\Shared\DefaultValue;

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

    /**
     * @param string $wireName the name in the schema, kept for serialization
     * @param list<AttributeModel> $attributes
     */
    public function __construct(
        string $name,
        string $wireName,
        TypeModel $type,
        bool $required,
        ?DefaultValue $default,
        DocModel $doc,
        SchemaLocation $source,
        array $attributes = []
    ) {
        if (!Identifier::isValid($name) || $name === 'this') {
            throw new InvalidModel(sprintf('"%s" is not a usable PHP property name (%s).', $name, $source->toString()));
        }

        if ($wireName === '') {
            throw new InvalidModel(sprintf('Property "%s" has an empty wire name (%s).', $name, $source->toString()));
        }

        $this->name = $name;
        $this->wireName = $wireName;
        $this->type = $type;
        $this->required = $required;
        $this->default = $default;
        $this->doc = $doc;
        $this->source = $source;
        $this->attributes = $attributes;
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
        );
    }
}
