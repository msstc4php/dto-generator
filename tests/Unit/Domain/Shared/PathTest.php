<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Shared;

use MSSTC4PHP\DtoGenerator\Domain\Shared\Path;
use PHPUnit\Framework\TestCase;

final class PathTest extends TestCase
{
    /**
     * @dataProvider normalizations
     */
    public function testNormalizes(string $path, string $expected): void
    {
        self::assertSame($expected, Path::normalize($path));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function normalizations(): array
    {
        return [
            'dots and double slashes' => ['/a/./b//c', '/a/b/c'],
            'parent' => ['/a/b/../c', '/a/c'],
            'parent above root' => ['/../a', '/a'],
            'relative parent kept' => ['a/../../b', '../b'],
            'windows' => ['C:\\x\\y\\..\\z', 'C:/x/z'],
            'current dir prefix' => ['./a', 'a'],
            'root' => ['/', '/'],
            'empty' => ['', ''],
        ];
    }

    /**
     * @dataProvider resolutions
     */
    public function testResolvesAgainstABaseDirectory(string $base, string $path, string $expected): void
    {
        self::assertSame($expected, Path::resolve($base, $path));
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function resolutions(): array
    {
        return [
            'relative' => ['/base/dir', 'spec/openapi.yaml', '/base/dir/spec/openapi.yaml'],
            'parent' => ['/base/dir', '../x.yaml', '/base/x.yaml'],
            'dot segment' => ['/base/dir', './other/../x.yaml', '/base/dir/x.yaml'],
            'absolute' => ['/base', '/abs/x.yaml', '/abs/x.yaml'],
            'windows absolute' => ['/base', 'C:/abs/x.yaml', 'C:/abs/x.yaml'],
        ];
    }

    /**
     * @dataProvider directories
     */
    public function testFindsTheDirectory(string $path, string $expected): void
    {
        self::assertSame($expected, Path::directory($path));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function directories(): array
    {
        return [
            'nested' => ['/a/b.yaml', '/a'],
            'in root' => ['/b.yaml', '/'],
            'root itself' => ['/', '/'],
            'relative file' => ['b.yaml', '.'],
            'windows drive' => ['C:/b.yaml', 'C:/'],
            'drive-like directory name' => ['/xC:/b.yaml', '/xC:'],
        ];
    }

    public function testRecognisesAbsolutePaths(): void
    {
        self::assertTrue(Path::isAbsolute('/a'));
        self::assertTrue(Path::isAbsolute('C:\\a'));
        self::assertTrue(Path::isAbsolute('\\\\server\\share'));
        self::assertFalse(Path::isAbsolute('a/b'));
        self::assertFalse(Path::isAbsolute('C:a'));
        self::assertFalse(Path::isAbsolute('dir/C:/x'));
    }
}
