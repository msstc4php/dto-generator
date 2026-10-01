<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Target;

use MSSTC4PHP\DtoGenerator\Domain\Exception\UnsupportedPhpVersion;
use MSSTC4PHP\DtoGenerator\Domain\Target\PhpVersion;
use PHPUnit\Framework\TestCase;

final class PhpVersionTest extends TestCase
{
    /**
     * @dataProvider validVersions
     */
    public function testParsesSupportedVersions(string $input, int $major, int $minor, int $id): void
    {
        $version = PhpVersion::fromString($input);

        self::assertSame($major, $version->major());
        self::assertSame($minor, $version->minor());
        self::assertSame($id, $version->id());
        self::assertSame($major . '.' . $minor, $version->toString());
    }

    /**
     * @return array<string, array{string, int, int, int}>
     */
    public static function validVersions(): array
    {
        return [
            'oldest' => ['7.4', 7, 4, 70400],
            'newest' => ['8.5', 8, 5, 80500],
            'patch is ignored' => ['8.2.15', 8, 2, 80200],
        ];
    }

    /**
     * @dataProvider malformedVersions
     */
    public function testRejectsMalformedInput(string $input): void
    {
        $this->expectException(UnsupportedPhpVersion::class);
        $this->expectExceptionMessage('is not a PHP version');

        PhpVersion::fromString($input);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedVersions(): array
    {
        return [
            'empty' => [''],
            'major only' => ['8'],
            'letters' => ['8.x'],
            'prefix' => ['v8.2'],
            'composer constraint' => ['^8.1'],
            'comparison constraint' => ['>=7.4'],
            'leading zero' => ['08.2'],
            'four parts' => ['8.2.1.0'],
        ];
    }

    /**
     * @dataProvider unsupportedVersions
     */
    public function testRejectsUnsupportedVersions(string $input): void
    {
        $this->expectException(UnsupportedPhpVersion::class);
        $this->expectExceptionMessage('is not supported');

        PhpVersion::fromString($input);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unsupportedVersions(): array
    {
        return [
            'too old' => ['7.3'],
            'ancient' => ['5.6'],
            'too new minor' => ['8.6'],
            'too new major' => ['9.0'],
        ];
    }

    public function testComparesVersions(): void
    {
        $php80 = PhpVersion::fromString('8.0');
        $php81 = PhpVersion::fromString('8.1');

        self::assertTrue($php81->isAtLeast($php80));
        self::assertTrue($php81->isAtLeast(PhpVersion::fromString('8.1.3')));
        self::assertFalse($php80->isAtLeast($php81));
        self::assertTrue($php81->equals(PhpVersion::fromString('8.1.9')));
        self::assertFalse($php81->equals($php80));
    }

    public function testExposesTheSupportedRange(): void
    {
        self::assertSame('7.4', PhpVersion::oldest()->toString());
        self::assertSame('8.5', PhpVersion::newest()->toString());
        self::assertSame(
            ['7.4', '8.0', '8.1', '8.2', '8.3', '8.4', '8.5'],
            array_map(static fn (PhpVersion $version): string => $version->toString(), PhpVersion::supported()),
        );
    }
}
