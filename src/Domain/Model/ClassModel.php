<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Model;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use MSSTC4PHP\DtoGenerator\Domain\Target\Mutability;

/**
 * @api
 */
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

    /** @var list<string> */
    private array $discriminatedProperties;

    /** @var list<string> */
    private array $restoredMutators;

    /**
     * @param list<PropertyModel> $properties own properties only, in schema order
     * @param list<AttributeModel> $attributes
     * @param list<DiscriminatorValues> $discriminatorValues root discriminator first
     * @param list<string> $discriminatedProperties wire names read by a discriminator of an ancestor or a subclass
     * @param list<string> $restoredMutators wire names of inherited properties whose mutators the parent leaves out but
     *                                       no discriminator of this class's chain reads
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
        array $discriminatorValues = [],
        array $discriminatedProperties = [],
        array $restoredMutators = []
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
        $this->discriminatedProperties = $this->wireNames($discriminatedProperties, $name, 'discriminated property');
        $this->restoredMutators = $this->wireNames($restoredMutators, $name, 'restored mutator');
        foreach (array_intersect($this->restoredMutators, $this->discriminatedProperties()) as $wireName) {
            throw new InvalidModel(sprintf('Class %s restores the mutators of "%s", which a discriminator of its chain reads.', $name->fqcn(), $wireName));
        }
    }

    /**
     * @param list<string> $wireNames
     *
     * @return list<string> without repeats, in their order
     */
    private function wireNames(array $wireNames, ClassName $name, string $what): array
    {
        $unique = [];
        foreach ($wireNames as $wireName) {
            if ($wireName === '') {
                throw new InvalidModel(sprintf('Class %s lists an empty %s.', $name->fqcn(), $what));
            }

            if (!in_array($wireName, $unique, true)) {
                $unique[] = $wireName;
            }
        }

        return $unique;
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

    /**
     * The wire names a discriminator of the class, an ancestor or a subclass reads: a mutator of one of them would
     * let an object claim another class.
     *
     * @return list<string>
     */
    public function discriminatedProperties(): array
    {
        $own = $this->discriminator instanceof DiscriminatorModel ? [$this->discriminator->propertyName()] : [];

        return array_merge($own, array_diff($this->discriminatedProperties, $own));
    }

    public function withDiscriminatedProperties(string ...$wireNames): self
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
            $this->discriminatorValues,
            $wireNames,
            $this->restoredMutators,
        );
    }

    /**
     * @return list<string>
     */
    public function restoredMutators(): array
    {
        return $this->restoredMutators;
    }

    public function withRestoredMutators(string ...$wireNames): self
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
            $this->discriminatorValues,
            $this->discriminatedProperties,
            $wireNames,
        );
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
            $this->discriminatedProperties,
            $this->restoredMutators,
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
            $this->discriminatedProperties,
            $this->restoredMutators,
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
            $this->discriminatedProperties,
            $this->restoredMutators,
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
            $this->discriminatedProperties,
            $this->restoredMutators,
        );
    }
}
