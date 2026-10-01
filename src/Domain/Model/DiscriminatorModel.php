<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Model;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;

final class DiscriminatorModel
{
    private string $propertyName;

    /**
     * Keys are discriminator values; PHP turns numeric ones into ints, hence `int|string`.
     *
     * @var non-empty-array<int|string, ClassName>
     */
    private array $mapping;

    /**
     * @param string $propertyName wire name of the discriminating property
     * @param array<int|string, ClassName> $mapping discriminator value → concrete class
     */
    public function __construct(string $propertyName, array $mapping)
    {
        if ($propertyName === '') {
            throw new InvalidModel('Discriminator property name must not be empty.');
        }

        if ($mapping === []) {
            throw new InvalidModel(sprintf('Discriminator "%s" has no mapping.', $propertyName));
        }

        $this->propertyName = $propertyName;
        $this->mapping = $mapping;
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

    public function classFor(string $value): ?ClassName
    {
        return $this->mapping[$value] ?? null;
    }
}
