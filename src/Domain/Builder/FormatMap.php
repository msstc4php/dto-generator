<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Model\TypeModel;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Formats;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;

/**
 * The formats the config and the extensions name: a type for a format, or only that a format of the project is known
 * for a kind of scalar. A format is looked up as the document writes it, then by its name, so `uuid` covers `GUID`.
 */
final class FormatMap
{
    /** @var array<int|string, TypeModel> */
    private array $types;

    /** @var array<string, TypeModel> */
    private array $typesByName = [];

    /** @var array<int|string, 'string'|'int'|'float'|'bool'> */
    private array $known;

    /** @var array<string, 'string'|'int'|'float'|'bool'> */
    private array $knownByName = [];

    /**
     * @param array<int|string, TypeModel> $types format → the type of its property; of two that share a name, the later
     * @param array<int|string, 'string'|'int'|'float'|'bool'> $known format → the scalar it is known for
     */
    public function __construct(array $types, array $known = [])
    {
        $this->types = $types;
        $this->known = $known;
        foreach ($types as $format => $type) {
            $this->typesByName[Formats::normalize((string) $format)] = $type;
        }

        foreach ($known as $format => $kind) {
            $this->knownByName[Formats::normalize((string) $format)] = $kind;
        }
    }

    public function typeOf(Schema $schema): ?TypeModel
    {
        $format = $schema->format();
        $name = $schema->normalizedFormat();
        if ($format === null || $name === null) {
            return null;
        }

        return $this->types[$format] ?? $this->typesByName[$name] ?? null;
    }

    /**
     * @param 'string'|'int'|'float'|'bool' $kind
     */
    public function knows(Schema $schema, string $kind): bool
    {
        $format = $schema->format();
        $name = $schema->normalizedFormat();
        if ($format === null || $name === null) {
            return false;
        }

        return ($this->known[$format] ?? $this->knownByName[$name] ?? null) === $kind;
    }
}
