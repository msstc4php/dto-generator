<?php

declare(strict_types=1);

// Collection classes the Basket golden names; plain PHP 7.4 so the file loads on every target.

namespace App\Dto;

final class TagList
{
    /** @var list<Tag> */
    private array $tags;

    /**
     * @param list<Tag> $tags
     */
    public function __construct(array $tags)
    {
        $this->tags = $tags;
    }

    /**
     * @return list<Tag>
     */
    public function toArray(): array
    {
        return $this->tags;
    }
}

namespace App\Collection;

final class StringList
{
    /**
     * @param list<string> $strings
     */
    public function __construct(array $strings)
    {
    }
}

/**
 * @template T
 */
final class ListOf
{
    /** @var list<T> */
    private array $elements;

    /**
     * @param list<T> $elements
     */
    public function __construct(array $elements)
    {
        $this->elements = $elements;
    }

    /**
     * @return list<T>
     */
    public function toArray(): array
    {
        return $this->elements;
    }
}

namespace Doctrine\Common\Collections;

/**
 * @template TKey of array-key
 * @template T
 */
final class ArrayCollection
{
    /**
     * @param array<TKey, T> $elements
     */
    public function __construct(array $elements)
    {
    }
}
