<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Builder\DefaultFit;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassType;
use MSSTC4PHP\DtoGenerator\Domain\Model\ScalarType;
use PHPUnit\Framework\TestCase;

final class DefaultFitTest extends TestCase
{
    /**
     * @dataProvider ranges
     */
    public function testChecksIntegerRangesUpToThePlatformLimits(string $range, int $value, bool $fits): void
    {
        self::assertSame($fits, DefaultFit::fits($value, ScalarType::int($range)));
    }

    /**
     * @return array<string, array{string, int, bool}>
     */
    public static function ranges(): array
    {
        $min = (string) PHP_INT_MIN;
        $max = (string) PHP_INT_MAX;

        return [
            'lowest int at the lower limit' => ["int<{$min}, 0>", PHP_INT_MIN, true],
            'highest int at the upper limit' => ["int<0, {$max}>", PHP_INT_MAX, true],
            'just above the upper bound' => ["int<{$min}, 0>", 1, false],
            'just below the lower bound' => ["int<0, {$max}>", -1, false],
        ];
    }

    public function testNoJsonValueIsAClassInstance(): void
    {
        self::assertFalse(DefaultFit::fits(1, new ClassType(ClassName::fromFqcn('App\Money'))));
    }
}
