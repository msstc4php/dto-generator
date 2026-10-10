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

    /** @var non-empty-list<Schema> */
    private array $ownParts;

    /** @var array<string, string> */
    private array $required;

    /**
     * @param list<Schema> $members the merged `allOf` members, in order
     * @param list<Schema> $ownMembers the members written inside the class schema, not reached through a $ref
     * @param array<string, string> $required wire names any member or the schema lists as required, by themselves
     */
    public function __construct(?ClassName $parent, Schema $schema, array $members, array $ownMembers, array $required)
    {
        $this->parent = $parent;
        $this->parts = array_merge($members, [$schema]);
        $this->ownParts = array_merge($ownMembers, [$schema]);
        $this->required = $required;
    }

    public static function of(Schema $schema): self
    {
        return (new CompositionParts())->finish($schema, null);
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
     * @return non-empty-list<Schema>
     */
    public function ownParts(): array
    {
        return $this->ownParts;
    }

    /**
     * Every declaration of a property across the parts, in their order: a property may be declared by several.
     *
     * @return list<array{string, Schema}> wire name and schema
     */
    public function propertySources(): array
    {
        $sources = [];
        foreach ($this->parts as $part) {
            foreach ($part->propertyNames() as $wireName) {
                $sources[] = [$wireName, $part->requireProperty($wireName)];
            }
        }

        return $sources;
    }

    public function isRequired(string $wireName): bool
    {
        return isset($this->required[$wireName]);
    }

    /**
     * @return array<string, string> wire names any part lists as required, by themselves
     */
    public function required(): array
    {
        return $this->required;
    }
}
