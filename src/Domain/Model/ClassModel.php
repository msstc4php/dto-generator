<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Model;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use MSSTC4PHP\DtoGenerator\Domain\Target\Mutability;

final class ClassModel
{
    private ClassName $name;

    private ClassKind $kind;

    private ?ClassName $parent;

    /** @var list<PropertyModel> */
    private array $properties;

    private Mutability $mutability;

    private DocModel $doc;

    private SchemaLocation $source;

    /** @var list<AttributeModel> */
    private array $attributes;

    private ?DiscriminatorModel $discriminator;

    /** @var list<DiscriminatorValues> */
    private array $discriminatorValues;

    /**
     * @param list<PropertyModel> $properties own properties only, in schema order
     * @param list<AttributeModel> $attributes
     * @param list<DiscriminatorValues> $discriminatorValues root discriminator first
     */
    public function __construct(
        ClassName $name,
        ClassKind $kind,
        ?ClassName $parent,
        array $properties,
        Mutability $mutability,
        DocModel $doc,
        SchemaLocation $source,
        array $attributes = [],
        ?DiscriminatorModel $discriminator = null,
        array $discriminatorValues = []
    ) {
        if ($parent instanceof ClassName && $parent->equals($name)) {
            throw new InvalidModel(sprintf('Class %s cannot extend itself.', $name->fqcn()));
        }

        if ($discriminator instanceof DiscriminatorModel && !$kind->isAbstract()) {
            throw new InvalidModel(sprintf('Only an abstract class can carry a discriminator; %s is %s.', $name->fqcn(), $kind->value()));
        }

        if ($discriminatorValues !== [] && $kind->isAbstract()) {
            throw new InvalidModel(sprintf('Only a concrete class is selected by discriminator values; %s is %s.', $name->fqcn(), $kind->value()));
        }

        $checked = [];
        foreach ($discriminatorValues as $values) {
            if (isset($checked[$values->property()])) {
                throw new InvalidModel(sprintf('Class %s has two sets of discriminator values for $%s.', $name->fqcn(), $values->property()));
            }

            $checked[$values->property()] = true;
        }

        $names = [];
        $wireNames = [];
        foreach ($properties as $property) {
            // Accessor methods are case-insensitive in PHP, so `foo` and `Foo` would both declare getFoo().
            $key = Identifier::asciiLower($property->name());
            if (isset($names[$key])) {
                throw new InvalidModel(sprintf('Class %s declares property "%s" twice.', $name->fqcn(), $property->name()));
            }

            if (isset($wireNames[$property->wireName()])) {
                throw new InvalidModel(sprintf('Class %s maps wire name "%s" twice.', $name->fqcn(), $property->wireName()));
            }

            $names[$key] = true;
            $wireNames[$property->wireName()] = true;
        }

        $this->name = $name;
        $this->kind = $kind;
        $this->parent = $parent;
        $this->properties = $properties;
        $this->mutability = $mutability;
        $this->doc = $doc;
        $this->source = $source;
        $this->attributes = $attributes;
        $this->discriminator = $discriminator;
        $this->discriminatorValues = $discriminatorValues;
    }

    public function name(): ClassName
    {
        return $this->name;
    }

    public function kind(): ClassKind
    {
        return $this->kind;
    }

    public function parent(): ?ClassName
    {
        return $this->parent;
    }

    /**
     * @return list<PropertyModel>
     */
    public function properties(): array
    {
        return $this->properties;
    }

    public function property(string $name): ?PropertyModel
    {
        foreach ($this->properties as $property) {
            if ($property->name() === $name) {
                return $property;
            }
        }

        return null;
    }

    public function mutability(): Mutability
    {
        return $this->mutability;
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

    public function discriminator(): ?DiscriminatorModel
    {
        return $this->discriminator;
    }

    /**
     * @return list<DiscriminatorValues> root discriminator first
     */
    public function discriminatorValues(): array
    {
        return $this->discriminatorValues;
    }

    public function discriminatorValuesOf(string $property): ?DiscriminatorValues
    {
        foreach ($this->discriminatorValues as $values) {
            if ($values->property() === $property) {
                return $values;
            }
        }

        return null;
    }

    public function withDiscriminatorValues(DiscriminatorValues ...$values): self
    {
        return new self(
            $this->name,
            $this->kind,
            $this->parent,
            $this->properties,
            $this->mutability,
            $this->doc,
            $this->source,
            $this->attributes,
            $this->discriminator,
            $values,
        );
    }

    public function withAddedAttributes(AttributeModel ...$attributes): self
    {
        return new self(
            $this->name,
            $this->kind,
            $this->parent,
            $this->properties,
            $this->mutability,
            $this->doc,
            $this->source,
            array_merge($this->attributes, $attributes),
            $this->discriminator,
            $this->discriminatorValues,
        );
    }

    public function withHierarchy(ClassKind $kind, ?ClassName $parent, ?DiscriminatorModel $discriminator): self
    {
        return new self(
            $this->name,
            $kind,
            $parent,
            $this->properties,
            $this->mutability,
            $this->doc,
            $this->source,
            $this->attributes,
            $discriminator,
            $this->discriminatorValues,
        );
    }

    public function withProperties(PropertyModel ...$properties): self
    {
        return new self(
            $this->name,
            $this->kind,
            $this->parent,
            $properties,
            $this->mutability,
            $this->doc,
            $this->source,
            $this->attributes,
            $this->discriminator,
            $this->discriminatorValues,
        );
    }
}
