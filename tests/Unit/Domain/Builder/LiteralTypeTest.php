<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Builder\LiteralType;
use PHPUnit\Framework\TestCase;

final class LiteralTypeTest extends TestCase
{
    public function testWritesScalarsAsPhpDocLiterals(): void
    {
        self::assertSame('5', LiteralType::of(5));
        self::assertSame('-3', LiteralType::of(-3));
        self::assertSame('true', LiteralType::of(true));
        self::assertSame('false', LiteralType::of(false));
        self::assertSame("'in-progress'", LiteralType::of('in-progress'));
        self::assertSame("''", LiteralType::of(''));
    }

    public function testLeavesUnsafeStringsUnrefined(): void
    {
        foreach (["it's", 'a|b', 'x*/', "two\nlines", 'back\\slash', 'é'] as $value) {
            self::assertNull(LiteralType::of($value), $value);
        }
    }

    public function testJoinsValuesIntoAUnion(): void
    {
        self::assertSame("'a'|'b'|1", LiteralType::union(['a', 'b', 1]));
        self::assertNull(LiteralType::union(['a', "it's"]));
        self::assertNull(LiteralType::union([]));
    }

    public function testTellsWhetherALiteralRefinementAdmitsAValue(): void
    {
        self::assertTrue(LiteralType::admits("'a'|'b'", 'b'));
        self::assertFalse(LiteralType::admits("'a'|'b'", 'c'));
        self::assertTrue(LiteralType::admits('5', 5));
        self::assertFalse(LiteralType::admits('5', '5'));
        self::assertTrue(LiteralType::admits('true', true));
        self::assertFalse(LiteralType::admits('true', 1.5));
        self::assertNull(LiteralType::admits('non-empty-string', 'x'));
        self::assertNull(LiteralType::admits('int<1, 5>', 3));
    }
}
