<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Integration\Infrastructure;

use MSSTC4PHP\DtoGenerator\Infrastructure\Remote\FetchFailed;
use MSSTC4PHP\DtoGenerator\Infrastructure\Remote\StreamFetcher;
use MSSTC4PHP\DtoGenerator\Tests\Support\RawServer;
use MSSTC4PHP\DtoGenerator\Tests\Support\RemoteServer;
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
        self::assertSame('not found', $response->body());
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

    private function url(string $path): string
    {
        self::assertInstanceOf(RemoteServer::class, self::$server);

        return self::$server->url($path);
    }
}
