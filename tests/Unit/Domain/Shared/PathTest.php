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
            'unc share' => ['\\\\server\\share\\x\\..\\y', '//server/share/y'],
            'drive letter case' => ['c:/a', 'C:/a'],
            'drive-relative' => ['c:foo', 'C:foo'],
            'unc cannot climb above the share' => ['//server/share/../../x', '//server/share/x'],
            'leading double slash without a share' => ['//dto.yaml', '/dto.yaml'],
            'double slash' => ['//', '/'],
            'dot server' => ['//../x', '/x'],
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
            'filesystem root' => ['/', 'a.yaml', '/a.yaml'],
            'drive root' => ['C:/', 'a.yaml', 'C:/a.yaml'],
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
            'unc' => ['//server/share/b.yaml', '//server/share'],
            'unc share root' => ['//server/share', '//server/share'],
            'leading double slash without a share' => ['//dto.yaml', '/'],
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
