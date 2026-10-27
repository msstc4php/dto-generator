<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Model\CollectionType;
use MSSTC4PHP\DtoGenerator\Domain\Model\NullableType;
use MSSTC4PHP\DtoGenerator\Domain\Model\PropertyModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\TypeModel;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;

/**
 * The properties that keep their list an array by `x-php-collection: false`. Their type is a plain list, which a
 * collection meets, so an allOf, merged or extended, asks here whether a member declined the collection it would get.
 */
final class CollectionDeclines
{
    /** @var list<string> locations of the declining property schemas */
    private array $declined = [];

    public function add(SchemaLocation $property): void
    {
        $this->declined[] = $property->toString();
    }

    /**
     * Whether two declarations of one property meet in a collection that one of them declines.
     */
    public function conflict(PropertyModel $a, PropertyModel $b, TypeModel $meet): bool
    {
        $meet = $meet instanceof NullableType ? $meet->inner() : $meet;

        return $meet instanceof CollectionType && array_intersect([$a->source()->toString(), $b->source()->toString()], $this->declined) !== [];
    }
}
