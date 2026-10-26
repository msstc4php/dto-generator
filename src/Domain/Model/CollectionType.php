<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Model;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;

/**
 * A list held by an object of a collection class instead of an array (`dto.collections`, `x-php-collection`).
 *
 * @api
 */
final class CollectionType implements TypeModel
{
    private const TYPE_ARGUMENTS = [[], ['item'], ['int', 'item']];

    private ClassName $className;

    private ListType $list;

    /** @var list<'int'|'item'> */
    private array $typeArguments;

    /**
     * @param list<string> $typeArguments what the PHPDoc type passes the class: `TagList`, `ListOf<Tag>`,
     *                                    `ArrayCollection<int, Tag>`
     */
    public function __construct(ClassName $className, ListType $list, array $typeArguments = [])
    {
        foreach (self::TYPE_ARGUMENTS as $allowed) {
            if ($typeArguments === $allowed) {
                $this->className = $className;
                $this->list = $list;
                $this->typeArguments = $allowed;

                return;
            }
        }

        throw new InvalidModel('Collection type arguments must be [], [item] or [int, item]');
    }

    public function className(): ClassName
    {
        return $this->className;
    }

    public function list(): ListType
    {
        return $this->list;
    }

    /**
     * @return list<'int'|'item'>
     */
    public function typeArguments(): array
    {
        return $this->typeArguments;
    }

    public function describe(): string
    {
        return sprintf('%s<%s>', $this->className->fqcn(), $this->list->item()->describe());
    }
}
