<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Model\CollectionType;
use MSSTC4PHP\DtoGenerator\Domain\Model\ListType;

/**
 * The class that holds a list instead of an array: a fixed class, or a pattern whose `{item}` becomes the short name
 * of the item type (`dto.collections.class`, `x-php-collection`).
 */
final class CollectionClass
{
    private const ITEM = '{item}';

    private const TYPE_ARGUMENTS = [[], ['item'], ['int', 'item']];

    private string $pattern;

    /** @var list<'int'|'item'>|null */
    private ?array $typeArguments;

    /**
     * @param list<'int'|'item'>|null $typeArguments
     */
    private function __construct(string $pattern, ?array $typeArguments)
    {
        $this->pattern = $pattern;
        $this->typeArguments = $typeArguments;
    }

    /**
     * @param list<string>|null $typeArguments null for the default: none with `{item}`, the item without
     */
    public static function fromPattern(string $pattern, ?array $typeArguments = null): self
    {
        $pattern = ltrim($pattern, '\\');
        $position = strrpos($pattern, '\\');
        $namespace = $position === false ? '' : substr($pattern, 0, $position);
        try {
            $valid = strpos($namespace, self::ITEM) === false && strpbrk(str_replace(self::ITEM, '', $pattern), '{}') === false;
            ClassName::fromFqcn(str_replace(self::ITEM, 'Item', $pattern));
        } catch (InvalidModel $exception) {
            $valid = false;
        }

        if (!$valid) {
            throw new InvalidModel(sprintf('"%s" must be a class name, optionally with {item} for the short name of the item type, such as App\Collection\{item}List.', $pattern));
        }

        return new self($pattern, $typeArguments === null ? null : self::checkedTypeArguments($typeArguments));
    }

    /**
     * Another class, for one property, with the type arguments the configuration names.
     */
    public function withPattern(string $pattern): self
    {
        return self::fromPattern($pattern, $this->typeArguments);
    }

    public function hasItem(): bool
    {
        return strpos($this->pattern, self::ITEM) !== false;
    }

    /**
     * @param string $item the short name `{item}` stands for
     */
    public function className(string $item): ClassName
    {
        $fqcn = str_replace(self::ITEM, $item, $this->pattern);
        try {
            return ClassName::fromFqcn($fqcn);
        } catch (InvalidModel $exception) {
            throw new InvalidModel(sprintf('"%s" gives "%s" for the item type %s, which is not a valid class name.', $this->pattern, $fqcn, $item));
        }
    }

    /**
     * @return list<'int'|'item'>
     */
    public function typeArguments(): array
    {
        return $this->typeArguments ?? ($this->hasItem() ? [] : ['item']);
    }

    public function wrap(ListType $list, string $item): CollectionType
    {
        return new CollectionType($this->className($item), $list, $this->typeArguments());
    }

    /**
     * @param list<string> $typeArguments
     *
     * @return list<'int'|'item'>
     */
    private static function checkedTypeArguments(array $typeArguments): array
    {
        foreach (self::TYPE_ARGUMENTS as $allowed) {
            if ($typeArguments === $allowed) {
                return $allowed;
            }
        }

        throw new InvalidModel('Collection type arguments must be [], [item] or [int, item]');
    }
}
