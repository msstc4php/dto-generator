<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Schema;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;

final class Discriminator
{
    private string $propertyName;

    /**
     * Keys are discriminator values; PHP turns numeric ones into ints, hence `int|string`.
     *
     * @var array<int|string, string>
     */
    private array $mapping;

    /**
     * @param array<int|string, string> $mapping discriminator value → `$ref`
     */
    public function __construct(string $propertyName, array $mapping = [])
    {
        if ($propertyName === '') {
            throw new InvalidModel('Discriminator property name must not be empty.');
        }

        foreach ($mapping as $value => $ref) {
            if ($ref === '') {
                throw new InvalidModel(sprintf('Discriminator value "%s" maps to an empty reference.', $value));
            }
        }

        $this->propertyName = $propertyName;
        $this->mapping = [];
        foreach ($mapping as $value => $ref) {
            $this->mapping[$value] = $this->isBareName($ref)
                ? '#' . str_replace('%', '%25', JsonPointer::fromSegments('components', 'schemas', $ref))
                : $ref;
        }
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

    public function refFor(string $value): ?string
    {
        return $this->mapping[$value] ?? null;
    }

    /**
     * OpenAPI: a mapping value without "/" or "#" names a component schema; a bare file name is still a URI.
     */
    private function isBareName(string $ref): bool
    {
        return strpos($ref, '#') === false
            && strpos($ref, '/') === false
            && preg_match('/\.(?:json|ya?ml)\z/i', $ref) !== 1;
    }
}
