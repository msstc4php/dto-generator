<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;

/**
 * Collects the merged `allOf` members of a class while they are walked.
 */
final class CompositionParts
{
    /** @var list<Schema> */
    private array $members = [];

    /** @var list<Schema> */
    private array $own = [];

    /** @var array<string, string> */
    private array $required = [];

    /**
     * @param bool $own whether the member is written inside the class schema, not reached through a $ref
     */
    public function add(Schema $member, bool $own): void
    {
        $this->members[] = $member;
        if ($own) {
            $this->own[] = $member;
        }

        $this->require($member);
    }

    public function finish(Schema $schema, ?ClassName $parent): Composition
    {
        $this->require($schema);

        return new Composition($parent, $schema, $this->members, $this->own, $this->required);
    }

    private function require(Schema $schema): void
    {
        foreach ($schema->required() as $wireName) {
            $this->required[$wireName] = $wireName;
        }
    }
}
