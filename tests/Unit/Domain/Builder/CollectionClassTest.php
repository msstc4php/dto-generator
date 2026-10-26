<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Builder\CollectionClass;
use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassType;
use MSSTC4PHP\DtoGenerator\Domain\Model\ListType;
use PHPUnit\Framework\TestCase;

final class CollectionClassTest extends TestCase
{
    public function testAPatternNamesAClassPerItem(): void
    {
        $collection = CollectionClass::fromPattern('\App\Collection\{item}List');

        self::assertTrue($collection->hasItem());
        self::assertSame('App\Collection\TagList', $collection->className('Tag')->fqcn());
        self::assertSame([], $collection->typeArguments());
    }

    public function testAFixedClassTakesTheItemAsTypeArgument(): void
    {
        $collection = CollectionClass::fromPattern('ArrayObject');

        self::assertFalse($collection->hasItem());
        self::assertSame('ArrayObject', $collection->className('Tag')->fqcn());
        self::assertSame(['item'], $collection->typeArguments());
    }

    public function testExplicitTypeArgumentsWin(): void
    {
        self::assertSame(['int', 'item'], CollectionClass::fromPattern('Doctrine\Common\Collections\ArrayCollection', ['int', 'item'])->typeArguments());
        self::assertSame([], CollectionClass::fromPattern('App\Bag', [])->typeArguments());
        self::assertSame(['item'], CollectionClass::fromPattern('App\{item}List', ['item'])->typeArguments());
    }

    public function testAnotherPatternKeepsExplicitTypeArguments(): void
    {
        $configured = CollectionClass::fromPattern('App\{item}List', ['int', 'item']);

        self::assertSame(['int', 'item'], $configured->withPattern('App\Bag')->typeArguments());
        self::assertSame(['item'], CollectionClass::fromPattern('App\{item}List')->withPattern('App\Bag')->typeArguments());
        self::assertSame('App\Bag', $configured->withPattern('App\Bag')->className('Tag')->fqcn());
    }

    public function testWrapsAList(): void
    {
        $list = new ListType(new ClassType(ClassName::fromFqcn('App\Dto\Tag')));
        $type = CollectionClass::fromPattern('App\Dto\{item}Collection')->wrap($list, 'Tag');

        self::assertSame('App\Dto\TagCollection', $type->className()->fqcn());
        self::assertSame($list, $type->list());
        self::assertSame([], $type->typeArguments());
    }

    /**
     * @dataProvider invalidPatterns
     *
     * @param list<string>|null $typeArguments
     */
    public function testRejectsAnInvalidPattern(string $pattern, ?array $typeArguments, string $message): void
    {
        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage($message);

        CollectionClass::fromPattern($pattern, $typeArguments);
    }

    /**
     * @return array<string, array{string, list<string>|null, string}>
     */
    public static function invalidPatterns(): array
    {
        $shape = 'must be a class name, optionally with {item} for the short name of the item type, such as App\Collection\{item}List';

        return [
            'empty' => ['', null, '"" ' . $shape],
            'other placeholder' => ['App\{type}List', null, '"App\{type}List" ' . $shape],
            'stray brace' => ['App\{item}List}', null, '"App\{item}List}" ' . $shape],
            'not a name' => ['App\{item} List', null, '"App\{item} List" ' . $shape],
            'item in the namespace' => ['App\{item}\Items', null, '"App\{item}\Items" ' . $shape],
            'reserved' => ['App\List', null, '"App\List" ' . $shape],
            'type arguments' => ['App\Bag', ['int'], 'Collection type arguments must be [], [item] or [int, item]'],
        ];
    }

    public function testAnItemThatMakesNoClassName(): void
    {
        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage('"App\{item}" gives "App\List" for the item type List, which is not a valid class name.');

        CollectionClass::fromPattern('App\{item}')->className('List');
    }
}
