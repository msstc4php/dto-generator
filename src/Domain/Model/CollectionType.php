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

    private ?string $pattern;

    /**
     * @param list<string> $typeArguments what the PHPDoc type passes the class: `TagList`, `ListOf<Tag>`,
     *                                    `ArrayCollection<int, Tag>`
     * @param string|null $pattern the class name it comes from, `{item}` standing for the item type; null when unknown
     */
    public function __construct(ClassName $className, ListType $list, array $typeArguments = [], ?string $pattern = null)
    {
        $this->className = $className;
        $this->list = $list;
        $this->typeArguments = self::checkedTypeArguments($typeArguments);
        $this->pattern = $pattern;
    }

    /**
     * @internal
     *
     * @param list<string> $typeArguments
     *
     * @return list<'int'|'item'>
     */
    public static function checkedTypeArguments(array $typeArguments): array
    {
        foreach (self::TYPE_ARGUMENTS as $allowed) {
            if ($typeArguments === $allowed) {
                return $allowed;
            }
        }

        throw new InvalidModel('Collection type arguments must be [], [item] or [int, item]');
    }

    /**
     * The class name this one comes from, `{item}` standing for the item type: two collections of one pattern differ
     * by their items only.
     */
    public function pattern(): ?string
    {
        return $this->pattern;
    }

    /**
     * Whether the class holds any items, so that narrower items keep it: its pattern names no `{item}`.
     */
    public function isGeneric(): bool
    {
        return $this->pattern !== null && strpos($this->pattern, '{item}') === false;
    }

    /**
     * The same class over other items.
     */
    public function withList(ListType $list): self
    {
        return new self($this->className, $list, $this->typeArguments, $this->pattern);
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
