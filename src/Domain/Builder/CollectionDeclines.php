<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Model\CollectionType;
use MSSTC4PHP\DtoGenerator\Domain\Model\NullableType;
use MSSTC4PHP\DtoGenerator\Domain\Model\PropertyModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\TypeModel;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;

/**
 * The lists kept an array by `x-php-collection: false`. Their type is a plain list, which a collection meets, so an
 * allOf asks here whether a declaration declined the collection it would get: by the declaring schema while a class
 * merges its members, and by class and property once built, as a member that narrows the list leaves its own location.
 */
final class CollectionDeclines
{
    /** @var list<string> locations of the declining property schemas */
    private array $locations = [];

    /** @var list<string> "class FQCN wire name" of the classes whose property declines */
    private array $properties = [];

    public function add(SchemaLocation $property): void
    {
        $this->locations[] = $property->toString();
    }

    public function declines(PropertyModel $property): bool
    {
        return in_array($property->source()->toString(), $this->locations, true);
    }

    public function addProperty(ClassName $class, string $wireName): void
    {
        $this->properties[] = $class->fqcn() . ' ' . $wireName;
    }

    public function declinedBy(ClassName $class, string $wireName): bool
    {
        return in_array($class->fqcn() . ' ' . $wireName, $this->properties, true);
    }

    public static function isCollection(TypeModel $type): bool
    {
        return ($type instanceof NullableType ? $type->inner() : $type) instanceof CollectionType;
    }
}
