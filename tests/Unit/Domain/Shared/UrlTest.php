<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Shared;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Url;
use PHPUnit\Framework\TestCase;

final class UrlTest extends TestCase
{
    public function testTellsAUrlFromAPath(): void
    {
        self::assertTrue(Url::isUrl('https://example.com/a.yaml'));
        self::assertTrue(Url::isUrl('HTTP://example.com/a.yaml'));
        self::assertTrue(Url::isUrl('ftp://example.com/a.yaml'));
        self::assertFalse(Url::isUrl('/project/api/a.yaml'));
        self::assertFalse(Url::isUrl('C:/project/a.yaml'));
        self::assertFalse(Url::isUrl('a.yaml'));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function normalized(): array
    {
        return [
            'case of scheme and host' => ['HTTPS://Schemas.Example.COM/Common/A.yaml', 'https://schemas.example.com/Common/A.yaml'],
            'default https port' => ['https://example.com:443/a.yaml', 'https://example.com/a.yaml'],
            'default http port' => ['http://example.com:80/a.yaml', 'http://example.com/a.yaml'],
            'other port' => ['https://example.com:8443/a.yaml', 'https://example.com:8443/a.yaml'],
            'dot segments' => ['https://example.com/a/./b/../c.yaml', 'https://example.com/a/c.yaml'],
            'climbing above the root' => ['https://example.com/../a.yaml', 'https://example.com/a.yaml'],
            'empty path' => ['https://example.com', 'https://example.com/'],
            'query' => ['https://example.com/a?v=1', 'https://example.com/a?v=1'],
            'trailing slash' => ['https://example.com/common/', 'https://example.com/common/'],
            'fragment dropped' => ['https://example.com/a.yaml#/x', 'https://example.com/a.yaml'],
        ];
    }

    /**
     * @dataProvider normalized
     */
    public function testNormalizes(string $url, string $expected): void
    {
        self::assertSame($expected, Url::normalize($url));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalid(): array
    {
        return [
            'scheme' => ['ftp://example.com/a.yaml', 'URL "ftp://example.com/a.yaml": only http and https are supported.'],
            'userinfo' => ['https://user:secret@example.com/a.yaml', 'URL "https://user:secret@example.com/a.yaml": credentials in a URL are not supported.'],
            'no host' => ['https:///a.yaml', 'URL "https:///a.yaml" is not a valid absolute URL.'],
            'not a url' => ['a.yaml', 'URL "a.yaml" is not a valid absolute URL.'],
        ];
    }

    /**
     * @dataProvider invalid
     */
    public function testRefusesWhatItCannotFetch(string $url, string $message): void
    {
        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage($message);

        Url::normalize($url);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function references(): array
    {
        return [
            'sibling' => ['money.yaml', 'https://example.com/common/v1/money.yaml'],
            'parent' => ['../v2/money.yaml', 'https://example.com/common/v2/money.yaml'],
            'root of the host' => ['/other/money.yaml', 'https://example.com/other/money.yaml'],
            'dot' => ['./money.yaml', 'https://example.com/common/v1/money.yaml'],
            'query' => ['money.yaml?v=2', 'https://example.com/common/v1/money.yaml?v=2'],
            'absolute' => ['https://Other.org/x.yaml', 'https://other.org/x.yaml'],
            'network path' => ['//other.org/x.yaml', 'https://other.org/x.yaml'],
        ];
    }

    /**
     * @dataProvider references
     */
    public function testResolvesAReferenceAgainstTheUrlOfItsDocument(string $reference, string $expected): void
    {
        self::assertSame($expected, Url::resolve('https://example.com/common/v1/api.yaml', $reference));
    }

    public function testTellsWhetherAUrlIsUnderAPrefix(): void
    {
        self::assertTrue(Url::isUnder('https://example.com/common/a.yaml', 'https://example.com/common/'));
        self::assertTrue(Url::isUnder('https://example.com/common/a.yaml', 'https://example.com/common/a.yaml'));
        self::assertFalse(Url::isUnder('https://example.com/commons/a.yaml', 'https://example.com/common/'));
        self::assertFalse(Url::isUnder('https://example.com/common', 'https://example.com/common/'));
        self::assertFalse(Url::isUnder('https://example.com.evil.org/common/a.yaml', 'https://example.com'));
        self::assertTrue(Url::isUnder('https://example.com/a.yaml', 'https://example.com'));
    }
}
