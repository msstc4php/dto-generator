<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Presentation\Cli;

use MSSTC4PHP\DtoGenerator\Presentation\Cli\MemoryLimit;
use PHPUnit\Framework\TestCase;

final class MemoryLimitTest extends TestCase
{
    /**
     * @dataProvider quantities
     */
    public function testReadsAnIniQuantity(string $quantity, ?int $bytes): void
    {
        self::assertSame($bytes, MemoryLimit::bytes($quantity));
    }

    /**
     * @return array<string, array{string, int|null}>
     */
    public static function quantities(): array
    {
        return [
            'bytes' => ['1073741824', 1073741824],
            'kilobytes' => ['2k', 2048],
            'megabytes' => ['3M', 3 * 1024 ** 2],
            'gigabytes' => ['2g', 2 * 1024 ** 3],
            'unlimited' => ['-1', PHP_INT_MAX],
            'too large to count' => ['99999999999999999G', PHP_INT_MAX],
            'just too large' => [(string) intdiv(PHP_INT_MAX, 1024 ** 3) + 1 . 'G', PHP_INT_MAX],
            'largest that fits' => [intdiv(PHP_INT_MAX, 1024 ** 3) . 'G', intdiv(PHP_INT_MAX, 1024 ** 3) * 1024 ** 3],
            'hexadecimal' => ['0x80000000', null],
            'two units' => ['512MB', null],
            'spaces' => [' 1G', null],
            'empty' => ['', null],
        ];
    }

    /**
     * @dataProvider raises
     */
    public function testRaisesOnlyALowerLimit(string $current, string $target, bool $raised): void
    {
        self::assertSame($raised, MemoryLimit::isBelow($current, $target));
    }

    /**
     * @return array<string, array{string, string, bool}>
     */
    public static function raises(): array
    {
        return [
            'lower' => ['128M', '1G', true],
            'just lower' => ['1073741823', '1G', true],
            'equal' => ['1048576K', '1G', false],
            'higher' => ['2G', '1G', false],
            'unlimited' => ['-1', '1G', false],
            'unreadable current' => ['0x80000000', '1G', false],
            'unreadable target' => ['128M', 'lots', false],
        ];
    }
}
