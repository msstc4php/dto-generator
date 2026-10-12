<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Infrastructure\Remote;

use MSSTC4PHP\DtoGenerator\Infrastructure\Remote\FetchFailed;
use MSSTC4PHP\DtoGenerator\Infrastructure\Remote\Proxies;
use MSSTC4PHP\DtoGenerator\Infrastructure\Remote\Proxy;
use PHPUnit\Framework\TestCase;

/**
 * Proxies from the environment (spec F3 §3).
 */
final class ProxiesTest extends TestCase
{
    public function testTakesTheProxyOfTheScheme(): void
    {
        $proxies = $this->proxies(['https_proxy' => 'http://secure.proxy:3128', 'http_proxy' => 'http://plain.proxy:8080']);

        self::assertSame('secure.proxy:3128', $this->label($proxies->for('https', 'a.example', 443)));
        self::assertSame('plain.proxy:8080', $this->label($proxies->for('http', 'a.example', 80)));
    }

    public function testPrefersTheLowerCaseVariableAndIgnoresAnEmptyOne(): void
    {
        self::assertSame('lower:1', $this->label($this->proxies(['https_proxy' => 'lower:1', 'HTTPS_PROXY' => 'upper:2'])->for('https', 'a.example', 443)));
        self::assertSame('upper:2', $this->label($this->proxies(['https_proxy' => '', 'HTTPS_PROXY' => 'upper:2'])->for('https', 'a.example', 443)));
        self::assertNull($this->proxies(['https_proxy' => ' '])->for('https', 'a.example', 443));
    }

    public function testIgnoresTheUpperCaseVariableForPlainHttp(): void
    {
        self::assertNull($this->proxies(['HTTP_PROXY' => 'http://injected:80'])->for('http', 'a.example', 80));
        self::assertNull($this->proxies(['https_proxy' => 'http://secure:80'])->for('http', 'a.example', 80));
        self::assertNull($this->proxies([])->for('https', 'a.example', 443));
    }

    public function testReadsTheAddressLikeCurl(): void
    {
        $plain = $this->proxies(['https_proxy' => 'proxy.local'])->for('https', 'a.example', 443);
        self::assertInstanceOf(Proxy::class, $plain);
        self::assertSame('proxy.local', $plain->host());
        self::assertSame(1080, $plain->port());
        self::assertNull($plain->authorization());

        $ipv6 = $this->proxies(['https_proxy' => 'HTTP://[::1]:3128/'])->for('https', 'a.example', 443);
        self::assertInstanceOf(Proxy::class, $ipv6);
        self::assertSame('[::1]', $ipv6->host());
        self::assertSame('[::1]:3128', $ipv6->label());
    }

    public function testTurnsTheCredentialsIntoBasicAuthorization(): void
    {
        $proxy = $this->proxies(['https_proxy' => 'http://us%40er:p%3Ass@proxy:3128'])->for('https', 'a.example', 443);
        $userOnly = $this->proxies(['https_proxy' => 'http://user@proxy:3128'])->for('https', 'a.example', 443);

        self::assertInstanceOf(Proxy::class, $proxy);
        self::assertSame('Basic ' . base64_encode('us@er:p:ss'), $proxy->authorization());
        self::assertSame('proxy:3128', $proxy->label());
        self::assertInstanceOf(Proxy::class, $userOnly);
        self::assertSame('Basic ' . base64_encode('user:'), $userOnly->authorization());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unusable(): array
    {
        return [
            'https proxy' => ['https://proxy:3128'],
            'socks' => ['socks5://proxy:1080'],
            'no host' => ['http://:3128'],
            'garbage' => ['http://'],
            'bad port' => ['http://proxy:99999'],
            'line break' => ["http://proxy:3128\r\nX: 1"],
        ];
    }

    /**
     * @dataProvider unusable
     */
    public function testRefusesAProxyItCannotUse(string $value): void
    {
        $this->expectException(FetchFailed::class);
        $this->expectExceptionMessage('the proxy in HTTPS_PROXY must be an http:// URL with a host');

        $this->proxies(['HTTPS_PROXY' => $value])->for('https', 'a.example', 443);
    }

    public function testDoesNotShowTheCredentialsOfAProxyItRefuses(): void
    {
        try {
            $this->proxies(['https_proxy' => 'https://user:secret@proxy:3128'])->for('https', 'a.example', 443);
            self::fail('No failure.');
        } catch (FetchFailed $exception) {
            self::assertStringNotContainsString('secret', $exception->getMessage());
        }
    }

    /**
     * @return array<string, array{string, string, int, bool}>
     */
    public static function exceptions(): array
    {
        return [
            'exact host' => ['a.example', 'a.example', 443, true],
            'subdomain' => ['example.com', 'api.example.com', 443, true],
            'leading dot' => ['.example.com', 'api.example.com', 443, true],
            'leading dot, the domain itself' => ['.example.com', 'example.com', 443, true],
            'not a boundary' => ['example.com', 'badexample.com', 443, false],
            'case' => ['EXAMPLE.com', 'api.example.com', 443, true],
            'star' => ['*', 'anything.example', 443, true],
            'list with spaces' => ['other.org, example.com', 'example.com', 443, true],
            'list with spaces only' => ['other.org example.com', 'example.com', 443, true],
            'empty entries' => [',,', 'example.com', 443, false],
            'port matches' => ['example.com:443', 'example.com', 443, true],
            'port differs' => ['example.com:8443', 'example.com', 443, false],
            'leading dot with port' => ['.example.com:443', 'example.com', 443, true],
            'ip' => ['127.0.0.1', '127.0.0.1', 80, true],
            'ip is no domain' => ['0.0.1', '127.0.0.1', 80, false],
            'ipv6 bare' => ['::1', '[::1]', 80, true],
            'ipv6 bracketed' => ['[::1]', '[::1]', 80, true],
            'ipv6 with port' => ['[::1]:80', '[::1]', 80, true],
            'ipv6 with another port' => ['[::1]:81', '[::1]', 80, false],
        ];
    }

    /**
     * @dataProvider exceptions
     */
    public function testGoesDirectlyToTheHostsNoProxyNames(string $noProxy, string $host, int $port, bool $direct): void
    {
        $proxy = $this->proxies(['https_proxy' => 'proxy:3128', 'http_proxy' => 'proxy:3128', 'no_proxy' => $noProxy])->for($port === 80 ? 'http' : 'https', $host, $port);

        self::assertSame($direct, !$proxy instanceof Proxy);
    }

    public function testReadsTheUpperCaseNoProxyToo(): void
    {
        self::assertNull($this->proxies(['https_proxy' => 'proxy:3128', 'NO_PROXY' => 'a.example'])->for('https', 'a.example', 443));
        self::assertNotNull($this->proxies(['https_proxy' => 'proxy:3128', 'no_proxy' => 'b.example', 'NO_PROXY' => 'a.example'])->for('https', 'a.example', 443));
    }

    public function testReadsTheEnvironmentByDefault(): void
    {
        $saved = [];
        foreach (['https_proxy', 'HTTPS_PROXY', 'no_proxy', 'NO_PROXY'] as $name) {
            $saved[$name] = getenv($name);
            putenv($name);
        }

        try {
            self::assertNull(Proxies::fromEnvironment()->for('https', 'a.example', 443));
            putenv('HTTPS_PROXY=http://from-env:3128');
            self::assertSame('from-env:3128', $this->label(Proxies::fromEnvironment()->for('https', 'a.example', 443)));
        } finally {
            foreach ($saved as $name => $value) {
                putenv($value === false ? $name : $name . '=' . $value);
            }
        }
    }

    /**
     * @param array<string, string> $variables
     */
    private function proxies(array $variables): Proxies
    {
        return Proxies::fromEnvironment(static fn (string $name): ?string => $variables[$name] ?? null);
    }

    private function label(?Proxy $proxy): ?string
    {
        return $proxy instanceof Proxy ? $proxy->label() : null;
    }
}
