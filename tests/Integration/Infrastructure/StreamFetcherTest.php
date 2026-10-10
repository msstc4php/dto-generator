<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Integration\Infrastructure;

use MSSTC4PHP\DtoGenerator\Infrastructure\Remote\FetchFailed;
use MSSTC4PHP\DtoGenerator\Infrastructure\Remote\StreamFetcher;
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
