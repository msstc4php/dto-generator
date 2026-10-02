<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;

/**
 * Where a class gets its properties from (spec §5.3): the parent it extends, and the schemas whose `properties` it
 * declares itself — `allOf` members merged in, then its own.
 */
final class Composition
{
    private ?ClassName $parent;

    /** @var non-empty-list<Schema> */
    private array $parts;

    /** @var list<Schema> */
    private array $ownParts;

    /** @var array<string, string> */
    private array $required;

    /**
     * @param non-empty-list<Schema> $parts
     * @param list<Schema> $ownParts the parts written inside the schema itself, not reached through a $ref
     * @param array<string, string> $required wire names any part lists as required, by themselves
     */
    public function __construct(?ClassName $parent, array $parts, array $ownParts, array $required)
    {
        $this->parent = $parent;
        $this->parts = $parts;
        $this->ownParts = $ownParts;
        $this->required = $required;
    }

    public static function of(Schema $schema): self
    {
        return new self(null, [$schema], [$schema], self::required($schema));
    }

    /**
     * @return array<string, string>
     */
    public static function required(Schema $schema): array
    {
        $required = [];
        foreach ($schema->required() as $wireName) {
            $required[$wireName] = $wireName;
        }

        return $required;
    }

    public function parent(): ?ClassName
    {
        return $this->parent;
    }

    /**
     * @return non-empty-list<Schema>
     */
    public function parts(): array
    {
        return $this->parts;
    }

    /**
     * The parts whose inline schemas this class names; parts merged in through a $ref belong to their own class.
     *
     * @return list<Schema>
     */
    public function ownParts(): array
    {
        return $this->ownParts;
    }

    public function isRequired(string $wireName): bool
    {
        return isset($this->required[$wireName]);
    }
}
