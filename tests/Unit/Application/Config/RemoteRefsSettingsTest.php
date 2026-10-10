<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Application\Config;

use InvalidArgumentException;
use MSSTC4PHP\DtoGenerator\Application\Config\RemoteRefsSettings;
use PHPUnit\Framework\TestCase;

final class RemoteRefsSettingsTest extends TestCase
{
    public function testAllowsTheUrlsUnderItsPrefixes(): void
    {
        $settings = new RemoteRefsSettings(['https://example.com/common/', 'https://other.org/one.yaml'], '/project/./cache/');

        self::assertTrue($settings->allows('https://example.com/common/v1/a.yaml'));
        self::assertTrue($settings->allows('https://other.org/one.yaml'));
        self::assertFalse($settings->allows('https://other.org/two.yaml'));
        self::assertFalse($settings->allows('https://example.com/commons/a.yaml'));
        self::assertFalse((new RemoteRefsSettings([], '/cache'))->allows('https://example.com/a.yaml'));
        self::assertSame('/project/cache', $settings->cacheDir());
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function invalid(): array
    {
        return ['relative directory' => ['cache', 10], 'no time' => ['/cache', 0]];
    }

    /**
     * @dataProvider invalid
     */
    public function testNeedsAnAbsoluteCacheAndSomeTime(string $cacheDir, int $timeout): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf('Remote $refs need an absolute cache directory and a positive timeout, got "%s" and %d.', $cacheDir, $timeout));

        // Reason: the test passes the timeout the type forbids, to see the constructor refuse it.
        // @phpstan-ignore argument.type
        new RemoteRefsSettings([], $cacheDir, $timeout);
    }
}
