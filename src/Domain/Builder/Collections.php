<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;

/**
 * `dto.collections`: which class holds the lists of DTO properties, and whether the generated item classes ask
 * dto-collection-generator for it through `#[TypedCollection]`.
 */
final class Collections
{
    public const IMMUTABLE = 'immutable';

    public const MUTABLE = 'mutable';

    private CollectionClass $collectionClass;

    /** @var self::IMMUTABLE|self::MUTABLE|null */
    private ?string $typedCollection;

    /**
     * @param self::IMMUTABLE|self::MUTABLE|null $typedCollection the dto-collection variant, or null for no attribute
     */
    public function __construct(CollectionClass $collectionClass, ?string $typedCollection = null)
    {
        if ($typedCollection !== null && !$collectionClass->hasItem()) {
            throw new InvalidModel('"typedCollection" needs {item} in "class": dto-collection-generator makes one class per item type.');
        }

        $this->collectionClass = $collectionClass;
        $this->typedCollection = $typedCollection;
    }

    public function collectionClass(): CollectionClass
    {
        return $this->collectionClass;
    }

    /**
     * @return self::IMMUTABLE|self::MUTABLE|null
     */
    public function typedCollection(): ?string
    {
        return $this->typedCollection;
    }
}
