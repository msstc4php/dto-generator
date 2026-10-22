<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Schema;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;

/**
 * @api
 */
final class Discriminator
{
    private string $propertyName;

    /**
     * Keys are discriminator values; PHP turns numeric ones into ints, hence `int|string`.
     *
     * @var array<int|string, string>
     */
    private array $mapping;

    private ?string $defaultMapping;

    /**
     * @param array<int|string, string> $mapping discriminator value → `$ref`
     * @param ?string $defaultMapping OpenAPI 3.2: the `$ref` of the variant for a missing or unmapped value
     */
    public function __construct(string $propertyName, array $mapping = [], ?string $defaultMapping = null)
    {
        if ($propertyName === '') {
            throw new InvalidModel('Discriminator property name must not be empty.');
        }

        foreach ($mapping as $value => $ref) {
            if ($ref === '') {
                throw new InvalidModel(sprintf('Discriminator value "%s" maps to an empty reference.', $value));
            }
        }

        if ($defaultMapping === '') {
            throw new InvalidModel('The default mapping of a discriminator must not be empty.');
        }

        $this->propertyName = $propertyName;
        $this->mapping = [];
        foreach ($mapping as $value => $ref) {
            $this->mapping[$value] = $this->reference($ref);
        }

        $this->defaultMapping = $defaultMapping === null ? null : $this->reference($defaultMapping);
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

    public function defaultRef(): ?string
    {
        return $this->defaultMapping;
    }

    private function reference(string $ref): string
    {
        return $this->isBareName($ref) ? '#' . str_replace('%', '%25', JsonPointer::fromSegments('components', 'schemas', $ref)) : $ref;
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
