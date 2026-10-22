<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;

/**
 * The property whose redeclaration asks a class to merge its allOf.
 */
final class RedeclaredProperty
{
    private string $wireName;

    private SchemaLocation $location;

    public function __construct(string $wireName, SchemaLocation $location)
    {
        $this->wireName = $wireName;
        $this->location = $location;
    }

    public function wireName(): string
    {
        return $this->wireName;
    }

    public function location(): SchemaLocation
    {
        return $this->location;
    }
}
