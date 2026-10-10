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
            'encoded dots' => ['https://example.com/common/%2e%2e/secret/s.yaml', 'https://example.com/secret/s.yaml'],
            'encoded dots in upper case' => ['https://example.com/common/%2E./s.yaml', 'https://example.com/s.yaml'],
            'one encoded dot' => ['https://example.com/common/.%2e/s.yaml', 'https://example.com/s.yaml'],
            'escapes of unreserved characters' => ['https://example.com/%7Euser/%61.yaml', 'https://example.com/~user/a.yaml'],
            'escapes of reserved characters' => ['https://example.com/a%3ab%20c.yaml?q=%3d', 'https://example.com/a%3Ab%20c.yaml?q=%3D'],
            'collapsing to the root' => ['https://example.com/a/..', 'https://example.com/'],
            'collapsing to the root with a dot' => ['https://example.com/a/b/../..', 'https://example.com/'],
            'empty query' => ['https://example.com/a.yaml?', 'https://example.com/a.yaml'],
            'ipv6' => ['http://[::1]:8080/a.yaml', 'http://[::1]:8080/a.yaml'],
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
            'scheme without slashes' => ['file:/etc/passwd', 'URL "file:/etc/passwd": only http and https are supported.'],
            'http without slashes' => ['https:example.com/a.yaml', 'URL "https:example.com/a.yaml" is not a valid absolute URL.'],
            'encoded slash' => ['https://example.com/common/..%2fsecret/s.yaml', 'URL "https://example.com/common/..%2fsecret/s.yaml": an encoded "/" or "\\" in the path is not supported.'],
            'encoded backslash' => ['https://example.com/common/..%5Csecret/s.yaml', 'an encoded "/" or "\\" in the path is not supported.'],
            'backslash' => ['https://example.com/common/..\\secret\\s.yaml', 'has characters a URL cannot hold; percent-encode them.'],
            'line break' => ["https://example.com/a\r\nHost: evil", 'has characters a URL cannot hold; percent-encode them.'],
            'space' => ['https://example.com/a b.yaml', 'has characters a URL cannot hold; percent-encode them.'],
            'broken escape' => ['https://example.com/a%zz.yaml', 'has characters a URL cannot hold; percent-encode them.'],
            'unicode host' => ['https://bücher.example/a.yaml', 'has characters a URL cannot hold; percent-encode them.'],
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
            'encoded parent' => ['%2e%2e/%2E%2E/x.yaml', 'https://example.com/x.yaml'],
        ];
    }

    /**
     * @dataProvider references
     */
    public function testResolvesAReferenceAgainstTheUrlOfItsDocument(string $reference, string $expected): void
    {
        self::assertSame($expected, Url::resolve('https://example.com/common/v1/api.yaml', $reference));
    }

    /**
     * @dataProvider normalized
     */
    public function testNormalizesOnce(string $url): void
    {
        self::assertSame(Url::normalize($url), Url::normalize(Url::normalize($url)));
    }

    public function testRefusesAnotherSchemeInAReference(): void
    {
        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage('URL "file:/etc/passwd": only http and https are supported.');

        Url::resolve('https://example.com/api.yaml', 'file:/etc/passwd');
    }

    public function testTellsASchemeFromAPath(): void
    {
        self::assertTrue(Url::hasScheme('mailto:a@b'));
        self::assertTrue(Url::hasScheme('file:/etc'));
        self::assertFalse(Url::hasScheme('a/b:c'));
        self::assertFalse(Url::hasScheme(':a'));
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
