<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Model;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;

/**
 * @api
 */
final class DiscriminatorModel
{
    private string $propertyName;

    /**
     * Keys are discriminator values; PHP turns numeric ones into ints, hence `int|string`.
     *
     * @var non-empty-array<int|string, ClassName>
     */
    private array $mapping;

    private ?ClassName $default;

    /**
     * @param string $propertyName wire name of the discriminating property
     * @param array<int|string, ClassName> $mapping discriminator value → concrete class
     * @param ?ClassName $default the class of a missing or unmapped value (OpenAPI 3.2 `defaultMapping`)
     */
    public function __construct(string $propertyName, array $mapping, ?ClassName $default = null)
    {
        if ($propertyName === '') {
            throw new InvalidModel('Discriminator property name must not be empty.');
        }

        if ($mapping === []) {
            throw new InvalidModel(sprintf('Discriminator "%s" has no mapping.', $propertyName));
        }

        $this->propertyName = $propertyName;
        $this->mapping = $mapping;
        $this->default = $default;
    }

    public function propertyName(): string
    {
        return $this->propertyName;
    }

    /**
     * @return list<string>
     */
    public function values(): array
    {
        return array_map('strval', array_keys($this->mapping));
    }

    /**
     * @return non-empty-array<int|string, ClassName>
     */
    public function mapping(): array
    {
        return $this->mapping;
    }

    public function defaultClass(): ?ClassName
    {
        return $this->default;
    }

    /**
     * The first value that selects the default class, as a Serializer that takes a default by value names it.
     */
    public function defaultValue(): ?string
    {
        foreach ($this->mapping as $value => $class) {
            if ($this->default instanceof ClassName && $class->fqcn() === $this->default->fqcn()) {
                return (string) $value;
            }
        }

        return null;
    }

    public function classFor(string $value): ?ClassName
    {
        return $this->mapping[$value] ?? null;
    }
}
