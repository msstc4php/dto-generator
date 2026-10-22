<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;

/**
 * The class schemas whose allOf a build merges although it could extend: a member redeclares an inherited property
 * with a type PHP lets no subclass declare, which Hierarchy finds once the types are known, for the next build.
 */
final class Merges
{
    /** @var array<string, true> by schema location */
    private array $locations = [];

    public function add(SchemaLocation $location): void
    {
        $this->locations[$location->toString()] = true;
    }

    public function has(SchemaLocation $location): bool
    {
        return isset($this->locations[$location->toString()]);
    }

    /**
     * Whether the other holds no location this one lacks.
     */
    public function covers(self $other): bool
    {
        return array_diff_key($other->locations, $this->locations) === [];
    }

    public function with(self $other): self
    {
        $merges = new self();
        $merges->locations = $this->locations + $other->locations;

        return $merges;
    }
}
