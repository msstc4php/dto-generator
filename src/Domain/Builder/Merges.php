<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;

/**
 * The class schemas whose allOf a build merges although it could extend: a member declares an inherited property again
 * with a type PHP lets no subclass declare, which Hierarchy finds once the types are known, for the next build.
 */
final class Merges
{
    /** @var array<string, array{string, SchemaLocation}> wire name and place of the first such property, by class schema */
    private array $properties = [];

    public function add(SchemaLocation $class, string $wireName, SchemaLocation $property): void
    {
        $this->properties[$class->toString()] ??= [$wireName, $property];
    }

    /**
     * @return array{string, SchemaLocation}|null the wire name and place of the property that asks for it
     */
    public function of(SchemaLocation $class): ?array
    {
        return $this->properties[$class->toString()] ?? null;
    }

    /**
     * Takes the class schema the other holds, if it does.
     */
    public function take(self $other, SchemaLocation $class): void
    {
        $property = $other->of($class);
        if ($property !== null) {
            $this->properties[$class->toString()] = $property;
        }
    }

    public function covers(self $other): bool
    {
        return array_diff_key($other->properties, $this->properties) === [];
    }

    public function addAll(self $other): void
    {
        $this->properties += $other->properties;
    }
}
