<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Integration\Infrastructure;

use MSSTC4PHP\DtoGenerator\Infrastructure\Remote\FetchFailed;
use MSSTC4PHP\DtoGenerator\Infrastructure\Remote\Proxies;
use MSSTC4PHP\DtoGenerator\Infrastructure\Remote\StreamFetcher;
use MSSTC4PHP\DtoGenerator\Tests\Support\ProxyServer;
use MSSTC4PHP\DtoGenerator\Tests\Support\RawServer;
use MSSTC4PHP\DtoGenerator\Tests\Support\RemoteServer;
use MSSTC4PHP\DtoGenerator\Tests\Support\TlsServer;
use PHPUnit\Framework\TestCase;

final class StreamFetcherTest extends TestCase
{
    private static ?RemoteServer $server = null;

    public static function setUpBeforeClass(): void
    {
        self::$server = RemoteServer::start();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server instanceof RemoteServer) {
            self::$server->stop();
            self::$server = null;
        }
    }

    public function testFetchesADocument(): void
    {
        $response = (new StreamFetcher())->get($this->url('/docs/currency.yaml'), 5, 1024);

        self::assertSame(200, $response->status());
        self::assertSame('application/yaml', $response->contentType());
        self::assertNull($response->location());
        self::assertSame("Currency:\n  type: string\n  enum: [EUR, USD]\n", $response->body());
    }

    public function testDoesNotFollowARedirect(): void
    {
        $response = (new StreamFetcher())->get($this->url('/moved.yaml'), 5, 1024);

        self::assertSame(301, $response->status());
        self::assertSame('/docs/money.yaml', $response->location());
    }

    public function testReturnsAnErrorStatusWithItsBody(): void
    {
        $response = (new StreamFetcher())->get($this->url('/docs/none.yaml'), 5, 1024);

        self::assertSame(404, $response->status());
    }

    public function testRefusesABodyOverTheLimit(): void
    {
        $this->expectException(FetchFailed::class);
        $this->expectExceptionMessage('it is larger than 1024 bytes');

        (new StreamFetcher())->get($this->url('/big.json'), 5, 1024);
    }

    public function testTakesABodyAtTheLimit(): void
    {
        $length = strlen('{"x": "' . str_repeat('a', 2048) . '"}');

        self::assertSame($length, strlen((new StreamFetcher())->get($this->url('/big.json'), 5, $length)->body()));
    }

    public function testGivesUpOnADocumentThatTakesTooLong(): void
    {
        $started = microtime(true);

        try {
            (new StreamFetcher())->get($this->url('/drip.yaml'), 1, 1024);
            self::fail('No failure');
        } catch (FetchFailed $exception) {
            self::assertSame('it took longer than 1 seconds', $exception->getMessage());
        }

        self::assertLessThan(2.0, microtime(true) - $started);
    }

    public function testRefusesHeadersOverTheirLimit(): void
    {
        $this->expectException(FetchFailed::class);
        $this->expectExceptionMessage('its headers are larger than 65536 bytes');

        (new StreamFetcher())->get($this->url('/headers.yaml'), 5, 1024);
    }

    public function testSendsTheQueryAndThePort(): void
    {
        $response = (new StreamFetcher())->get($this->url('/docs/currency.yaml?v=1'), 5, 1024);

        self::assertSame(200, $response->status());
        self::assertStringStartsWith('Currency:', $response->body());
    }

    public function testRefusesAServerWhoseCertificateItCannotVerify(): void
    {
        $openssl = trim((string) shell_exec('command -v openssl'));
        if ($openssl === '') {
            self::markTestSkipped('openssl is not installed.');
        }

        $dir = sys_get_temp_dir() . '/tls-' . bin2hex(random_bytes(4));
        mkdir($dir);
        exec(sprintf('%s req -x509 -newkey rsa:2048 -nodes -subj /CN=127.0.0.1 -days 1 -keyout %s -out %s 2>/dev/null', escapeshellarg($openssl), escapeshellarg($dir . '/key.pem'), escapeshellarg($dir . '/cert.pem')));
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        $port = substr($name, (int) strrpos($name, ':') + 1);
        $server = proc_open([$openssl, 's_server', '-accept', $port, '-cert', $dir . '/cert.pem', '-key', $dir . '/key.pem', '-www', '-quiet'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        self::assertIsResource($server);

        try {
            for ($attempt = 0; $attempt < 100; $attempt++) {
                $connection = @fsockopen('127.0.0.1', (int) $port);
                if (is_resource($connection)) {
                    fclose($connection);

                    break;
                }

                usleep(20000);
            }

            $this->expectException(FetchFailed::class);
            $this->expectExceptionMessageMatches('~certificate verify failed~i');

            (new StreamFetcher())->get('https://' . $name . '/a.yaml', 5, 1024);
        } finally {
            proc_terminate($server);
            proc_close($server);
            foreach ((array) glob($dir . '/*') as $file) {
                unlink((string) $file);
            }
            rmdir($dir);
        }
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function brokenAnswers(): array
    {
        return [
            'short body' => ["HTTP/1.0 200 OK\r\nContent-Length: 1000\r\n\r\nA: {}", 'it ended after 5 of 1000 bytes'],
            'announced too much' => ["HTTP/1.0 200 OK\r\nContent-Length: 2000\r\n\r\nA: {}", 'it is larger than 1024 bytes'],
            'two lengths' => ["HTTP/1.0 200 OK\r\nContent-Length: 5\r\nContent-Length: 6\r\n\r\nA: {}", 'its Content-Length is not one number'],
            'length not a number' => ["HTTP/1.0 200 OK\r\nContent-Length: 5x\r\n\r\nA: {}", 'its Content-Length is not one number'],
            'chunked' => ["HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n5\r\nA: {}\r\n0\r\n\r\n", 'it answered an HTTP/1.0 request with Transfer-Encoding chunked'],
            'control characters' => ["HTTP/1.0 301 Moved\r\nLocation: http://x/\x1b[31mred\r\n\r\n", 'its headers hold control characters'],
            'not http' => ["SSH-2.0-OpenSSH\r\n\r\n", 'the server did not answer in HTTP'],
            'space before the colon' => ["HTTP/1.0 200 OK\r\nTransfer-Encoding : chunked\r\n\r\n5\r\nA: {}\r\n0\r\n\r\n", 'its headers are malformed'],
        ];
    }

    /**
     * @dataProvider brokenAnswers
     */
    public function testRefusesAnAnswerItCannotTrust(string $answer, string $message): void
    {
        $server = RawServer::answering($answer);

        try {
            $this->expectException(FetchFailed::class);
            $this->expectExceptionMessage($message);

            (new StreamFetcher())->get($server->url('/a.yaml'), 5, 1024);
        } finally {
            $server->stop();
        }
    }

    public function testReadsTheAnnouncedLengthOnly(): void
    {
        $server = RawServer::answering("HTTP/1.0 200 OK\r\nContent-Length: 5\r\nContent-Length: 5\r\n\r\nA: {}EXTRA");

        try {
            self::assertSame('A: {}', (new StreamFetcher())->get($server->url('/a.yaml'), 5, 1024)->body());
        } finally {
            $server->stop();
        }
    }

    public function testTakesTheSpacesAroundAHeaderValueOff(): void
    {
        $server = RawServer::answering("HTTP/1.0 200 OK\r\nContent-Length: 5 \r\nContent-Length:\t5\r\nContent-Type:  application/yaml \r\n\r\nA: {}");

        try {
            $response = (new StreamFetcher())->get($server->url('/a.yaml'), 5, 1024);
            self::assertSame('A: {}', $response->body());
            self::assertSame('application/yaml', $response->contentType());
        } finally {
            $server->stop();
        }
    }

    public function testReadsHeadersWithAnyTokenCharactersInTheirNames(): void
    {
        $server = RawServer::answering("HTTP/1.0 200 OK\r\nX_Backend: a\r\nX.Cache!#\$%&'*+^`|~: hit\r\nContent-Length: 5\r\n\r\nA: {}");

        try {
            self::assertSame('A: {}', (new StreamFetcher())->get($server->url('/a.yaml'), 5, 1024)->body());
        } finally {
            $server->stop();
        }
    }

    public function testKeepsTheStatusOfAMovedAnswerWithTransferEncoding(): void
    {
        $server = RawServer::answering("HTTP/1.0 301 Moved\r\nLocation: /b.yaml\r\nTransfer-Encoding: chunked\r\n\r\n0\r\n\r\n");

        try {
            $response = (new StreamFetcher())->get($server->url('/a.yaml'), 5, 1024);
            self::assertSame(301, $response->status());
            self::assertSame('/b.yaml', $response->location());
        } finally {
            $server->stop();
        }
    }

    public function testKeepsTheStatusOfAnAnswerItDoesNotRead(): void
    {
        $server = RawServer::answering("HTTP/1.0 404 Not Found\r\nContent-Length: 99999\r\n\r\nnope");

        try {
            $response = (new StreamFetcher())->get($server->url('/a.yaml'), 5, 1024);
            self::assertSame(404, $response->status());
            self::assertSame('', $response->body());
        } finally {
            $server->stop();
        }
    }

    public function testBoundsTheHandshakeByTheDeadline(): void
    {
        // Accepts and stays silent: a TLS client waits for the server's hello. A local connect is instant, so this
        // bounds the handshake rather than telling it from the connect timeout.
        $server = RawServer::answering('', 3);
        $started = microtime(true);

        try {
            $this->expectException(FetchFailed::class);
            $this->expectExceptionMessage('it took longer than 1 seconds');

            (new StreamFetcher())->get(str_replace('http://', 'https://', $server->url('/a.yaml')), 1, 1024);
        } finally {
            $server->stop();
            self::assertLessThan(1.5, microtime(true) - $started);
        }
    }

    public function testFetchesOnlyANormalizedUrl(): void
    {
        $this->expectException(FetchFailed::class);
        $this->expectExceptionMessage('"http://127.0.0.1:9/a/../b.yaml" is no normalized http(s) URL');

        (new StreamFetcher())->get('http://127.0.0.1:9/a/../b.yaml', 5, 1024);
    }

    public function testReportsAServerThatDoesNotAnswer(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        $this->expectException(FetchFailed::class);
        $this->expectExceptionMessageMatches('~Connection refused|failed to open stream~i');

        (new StreamFetcher())->get('http://' . $name . '/a.yaml', 2, 1024);
    }

    public function testSendsItsHeadersAndLetsThemReplaceItsOwn(): void
    {
        $server = ProxyServer::start();
        try {
            $response = $this->fetcher([])->get('http://127.0.0.1:' . $server->port() . '/a.yaml', 5, 1024, ['accept' => 'text/plain', 'X-Token' => "t\tu"]);

            self::assertSame('A: {}', $response->body());
            self::assertSame("GET /a.yaml HTTP/1.0\r\nHost: 127.0.0.1:" . $server->port() . "\r\naccept: text/plain\r\nUser-Agent: msstc4php-dto-generator\r\nX-Token: t\tu\r\nConnection: close\r\n\r\n", $server->log());
        } finally {
            $server->stop();
        }
    }

    /**
     * @return array<string, array{array<string, string>, string}>
     */
    public static function unsendableHeaders(): array
    {
        return [
            'line break in the value' => [['X-Token' => "a\r\nX-Injected: 1"], 'the header X-Token cannot be sent'],
            'delete in the value' => [['X-Token' => "a\x7F"], 'the header X-Token cannot be sent'],
            'space in the name' => [["X\nY" => 'v'], 'the header X?Y cannot be sent'],
            'host' => [['Host' => 'elsewhere.example'], 'the header Host cannot be sent'],
            'proxy authorization' => [['Proxy-Authorization' => 'Basic x'], 'the header Proxy-Authorization cannot be sent'],
        ];
    }

    /**
     * @dataProvider unsendableHeaders
     *
     * @param array<string, string> $headers
     */
    public function testRefusesAHeaderItCannotSend(array $headers, string $message): void
    {
        $this->expectException(FetchFailed::class);
        $this->expectExceptionMessage($message);

        $this->fetcher([])->get($this->url('/docs/currency.yaml'), 5, 1024, $headers);
    }

    public function testFetchesPlainHttpThroughTheProxyByTheAbsoluteUrl(): void
    {
        $proxy = ProxyServer::start();
        try {
            $response = $this->fetcher(['http_proxy' => 'http://us%3Aer:pw@127.0.0.1:' . $proxy->port()])->get('http://schemas.example.com:8080/a.yaml?v=1', 5, 1024, ['X-Token' => 't']);

            self::assertSame(200, $response->status());
            self::assertSame('A: {}', $response->body());
            self::assertSame(
                "GET http://schemas.example.com:8080/a.yaml?v=1 HTTP/1.0\r\nHost: schemas.example.com:8080\r\nAccept: application/json, application/yaml;q=0.9, */*;q=0.5\r\nUser-Agent: msstc4php-dto-generator\r\nX-Token: t\r\nProxy-Authorization: Basic " . base64_encode('us:er:pw') . "\r\nConnection: close\r\n\r\n",
                $proxy->log(),
            );
        } finally {
            $proxy->stop();
        }
    }

    public function testFetchesPlainHttpThroughAProxyWithoutCredentials(): void
    {
        $proxy = ProxyServer::start();
        try {
            $this->fetcher(['http_proxy' => '127.0.0.1:' . $proxy->port()])->get('http://schemas.example.com/a.yaml', 5, 1024);

            self::assertStringStartsWith("GET http://schemas.example.com/a.yaml HTTP/1.0\r\nHost: schemas.example.com\r\n", $proxy->log());
            self::assertStringNotContainsString('Proxy-Authorization', $proxy->log());
        } finally {
            $proxy->stop();
        }
    }

    public function testTunnelsHttpsThroughTheProxyToTheServer(): void
    {
        $server = $this->tlsServer('DNS:localhost');
        $proxy = ProxyServer::start();
        try {
            $url = 'https://localhost:' . $server->port() . '/a.yaml';
            self::assertSame('A: {}', $this->fetcher([], $server->certificate())->get($url, 5, 1024)->body());

            $tunnelled = $this->fetcher(['https_proxy' => 'http://user:pw@127.0.0.1:' . $proxy->port()], $server->certificate())->get($url, 5, 1024, ['X-Token' => 't']);

            self::assertSame('A: {}', $tunnelled->body());
            // The proxy saw the tunnel only; the request and its headers reached the server through it.
            self::assertSame("CONNECT localhost:{$server->port()} HTTP/1.1\r\nHost: localhost:{$server->port()}\r\nProxy-Authorization: Basic " . base64_encode('user:pw') . "\r\n\r\n", $proxy->log());
            self::assertStringEndsWith("GET /a.yaml HTTP/1.0\r\nHost: localhost:{$server->port()}\r\nAccept: application/json, application/yaml;q=0.9, */*;q=0.5\r\nUser-Agent: msstc4php-dto-generator\r\nX-Token: t\r\nConnection: close\r\n\r\n", $server->log());
        } finally {
            $proxy->stop();
            $server->stop();
        }
    }

    public function testChecksTheCertificateAgainstTheServerNotTheProxy(): void
    {
        // Valid for the proxy's address only: a client that checked the proxy's name would accept it.
        $server = $this->tlsServer('IP:127.0.0.1');
        $proxy = ProxyServer::start();
        try {
            $this->expectException(FetchFailed::class);
            $this->expectExceptionMessageMatches('~did not match expected CN|certificate verify failed~i');

            $this->fetcher(['https_proxy' => '127.0.0.1:' . $proxy->port()], $server->certificate())->get('https://localhost:' . $server->port() . '/a.yaml', 5, 1024);
        } finally {
            $proxy->stop();
            $server->stop();
        }
    }

    public function testReportsAProxyThatWantsCredentials(): void
    {
        $proxy = ProxyServer::start(true);
        try {
            $this->expectException(FetchFailed::class);
            $this->expectExceptionMessage('the proxy at 127.0.0.1:' . $proxy->port() . ' requires authentication (407)');

            $this->fetcher(['https_proxy' => '127.0.0.1:' . $proxy->port()])->get('https://schemas.example.com/a.yaml', 5, 1024);
        } finally {
            $proxy->stop();
        }
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function refusedTunnels(): array
    {
        return [
            'bad gateway' => ["HTTP/1.1 502 Bad Gateway\r\n\r\n", 'answered 502'],
            'informational' => ["HTTP/1.1 199 Odd\r\n\r\n", 'answered 199'],
            'redirect' => ["HTTP/1.1 300 Choices\r\n\r\n", 'answered 300'],
        ];
    }

    /**
     * @dataProvider refusedTunnels
     */
    public function testReportsAProxyThatRefusesTheTunnel(string $answer, string $problem): void
    {
        $proxy = RawServer::answering($answer);
        $port = (int) substr($proxy->url(''), strlen('http://127.0.0.1:'));
        try {
            $this->expectException(FetchFailed::class);
            $this->expectExceptionMessage('the proxy at 127.0.0.1:' . $port . ' ' . $problem);

            $this->fetcher(['https_proxy' => '127.0.0.1:' . $port])->get('https://schemas.example.com/a.yaml', 5, 1024);
        } finally {
            $proxy->stop();
        }
    }

    public function testTakesAnyTwoHundredAnswerAsTheTunnel(): void
    {
        // A tunnel the proxy says is open but that closes at once: the failure is the handshake's, not the proxy's.
        $proxy = RawServer::answering("HTTP/1.1 299 Fine\r\n\r\n");
        $port = (int) substr($proxy->url(''), strlen('http://127.0.0.1:'));
        try {
            $this->fetcher(['https_proxy' => '127.0.0.1:' . $port])->get('https://schemas.example.com/a.yaml', 5, 1024);
            self::fail('No failure.');
        } catch (FetchFailed $exception) {
            self::assertStringNotContainsString('the proxy at', $exception->getMessage());
        } finally {
            $proxy->stop();
        }
    }

    public function testReportsAProxyItCannotReach(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        $this->expectException(FetchFailed::class);
        $this->expectExceptionMessageMatches('~^the proxy at ' . preg_quote($name, '~') . ': .*(Connection refused|failed to open stream)~i');

        $this->fetcher(['https_proxy' => 'http://' . $name])->get('https://schemas.example.com/a.yaml', 2, 1024);
    }

    public function testReportsAProxyItCannotUse(): void
    {
        $this->expectException(FetchFailed::class);
        $this->expectExceptionMessage('the proxy in https_proxy must be an http:// URL with a host');

        $this->fetcher(['https_proxy' => 'socks5://127.0.0.1:1080'])->get('https://schemas.example.com/a.yaml', 2, 1024);
    }

    /**
     * @param array<string, string> $environment
     */
    private function fetcher(array $environment, ?string $caFile = null): StreamFetcher
    {
        return new StreamFetcher(Proxies::fromEnvironment(static fn (string $name): ?string => $environment[$name] ?? null), $caFile);
    }

    private function tlsServer(string $subjectAltName): TlsServer
    {
        $server = TlsServer::start($subjectAltName);
        if (!$server instanceof TlsServer) {
            self::markTestSkipped('openssl is not installed.');
        }

        return $server;
    }

    private function url(string $path): string
    {
        self::assertInstanceOf(RemoteServer::class, self::$server);

        return self::$server->url($path);
    }
}
