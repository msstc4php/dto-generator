<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Builder\EnumOfItems;
use PHPUnit\Framework\TestCase;

final class EnumOfItemsTest extends TestCase
{
    /**
     * @return iterable<string, array{array<array-key, mixed>, array<array-key, mixed>}>
     */
    public static function moved(): iterable
    {
        yield 'to typed items' => [
            ['type' => 'array', 'enum' => ['EU', 'US'], 'items' => ['type' => 'string']],
            ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['EU', 'US']]],
        ];
        yield 'to missing items' => [['type' => 'array', 'enum' => [1, 2]], ['type' => 'array', 'items' => ['enum' => [1, 2]]]];
        yield 'integers' => [
            ['type' => 'array', 'enum' => [1, 2], 'items' => ['type' => 'integer']],
            ['type' => 'array', 'items' => ['type' => 'integer', 'enum' => [1, 2]]],
        ];
        yield 'integers to numbers' => [
            ['type' => 'array', 'enum' => [1, 2.5], 'items' => ['type' => 'number']],
            ['type' => 'array', 'items' => ['type' => 'number', 'enum' => [1, 2.5]]],
        ];
        yield 'booleans' => [
            ['type' => 'array', 'enum' => [true], 'items' => ['type' => ['boolean', 'null']]],
            ['type' => 'array', 'items' => ['type' => ['boolean', 'null'], 'enum' => [true]]],
        ];
        yield 'of a nullable array, without null' => [
            ['type' => ['array', 'null'], 'enum' => [null, 'a']],
            ['type' => ['array', 'null'], 'items' => ['enum' => ['a']]],
        ];
    }

    /**
     * @dataProvider moved
     *
     * @param array<array-key, mixed> $node
     * @param array<array-key, mixed> $expected
     */
    public function testMovesTheScalarEnumOfAnArrayToItsItems(array $node, array $expected): void
    {
        self::assertSame($expected, EnumOfItems::moved($node));
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>}>
     */
    public static function kept(): iterable
    {
        yield 'not an array' => [['type' => 'string', 'enum' => ['a']]];
        yield 'untyped' => [['enum' => ['a']]];
        yield 'array or string' => [['type' => ['array', 'string'], 'enum' => ['a']]];
        yield 'enum of arrays' => [['type' => 'array', 'enum' => [['a']]]];
        yield 'enum of arrays and scalars' => [['type' => 'array', 'enum' => ['a', ['a']]]];
        yield 'only null' => [['type' => ['array', 'null'], 'enum' => [null]]];
        yield 'null the type rejects' => [['type' => 'array', 'enum' => ['a', null]]];
        yield 'items of a reference' => [['type' => 'array', 'enum' => ['a'], 'items' => ['$ref' => '#/S']]];
        yield 'items with an enum' => [['type' => 'array', 'enum' => ['a'], 'items' => ['enum' => ['b']]]];
        yield 'items with a const' => [['type' => 'array', 'enum' => ['a'], 'items' => ['const' => 'b']]];
        yield 'items of another type' => [['type' => 'array', 'enum' => ['a', 1], 'items' => ['type' => 'string']]];
        yield 'fraction for integer items' => [['type' => 'array', 'enum' => [1.5], 'items' => ['type' => 'integer']]];
        yield 'items of no type the parser reads' => [['type' => 'array', 'enum' => ['a'], 'items' => ['type' => 5]]];
        yield 'enum no list' => [['type' => 'array', 'enum' => 'a']];
        yield 'items no object' => [['type' => 'array', 'enum' => ['a'], 'items' => true]];
    }

    /**
     * @dataProvider kept
     *
     * @param array<array-key, mixed> $node
     */
    public function testKeepsAnyOtherSchema(array $node): void
    {
        self::assertSame($node, EnumOfItems::moved($node));
    }
}
