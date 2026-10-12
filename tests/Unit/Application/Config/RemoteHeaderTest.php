<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Application\Config;

use InvalidArgumentException;
use MSSTC4PHP\DtoGenerator\Application\Config\RemoteHeader;
use MSSTC4PHP\DtoGenerator\Application\Config\RemoteRefsSettings;
use PHPUnit\Framework\TestCase;

/**
 * Headers of remote documents (spec F3 §2).
 */
final class RemoteHeaderTest extends TestCase
{
    public function testRendersItsVariables(): void
    {
        $header = new RemoteHeader('https://git.example.com/api/', 'Authorization', 'Bearer ${TOKEN} $x ${A_1}$');

        self::assertSame('https://git.example.com/api/', $header->prefix());
        self::assertSame('Authorization', $header->name());
        self::assertSame(['TOKEN', 'A_1'], $header->variables());
        self::assertSame('Bearer secret $x ${TOKEN}$', $header->render(['TOKEN' => 'secret', 'A_1' => '${TOKEN}']));
        self::assertSame([], (new RemoteHeader('https://a.example/', 'X-Api-Version', '2'))->variables());
        self::assertSame(['T'], (new RemoteHeader('https://a.example/', 'X', '${T}${T}'))->variables());
    }

    public function testNeedsEveryVariableToRender(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The header Authorization needs the variable TOKEN.');

        (new RemoteHeader('https://a.example/', 'Authorization', 'Bearer ${TOKEN}'))->render([]);
    }

    /**
     * @return array<string, array{string, string, string, string}>
     */
    public static function invalid(): array
    {
        return [
            'plain http' => ['http://a.example/', 'X', 'v', 'A header is sent only over https, not to "http://a.example/".'],
            'not normalized' => ['HTTPS://a.example/', 'X', 'v', 'A header is sent only over https, not to "HTTPS://a.example/".'],
            'name with a space' => ['https://a.example/', 'X Y', 'v', 'A header name must be an HTTP token, not "X Y".'],
            'empty name' => ['https://a.example/', '', 'v', 'A header name must be an HTTP token, not "".'],
            'host' => ['https://a.example/', 'HOST', 'v', 'The header HOST is written by the generator itself.'],
            'connection' => ['https://a.example/', 'connection', 'v', 'The header connection is written by the generator itself.'],
            'content length' => ['https://a.example/', 'Content-Length', 'v', 'The header Content-Length is written by the generator itself.'],
            'transfer encoding' => ['https://a.example/', 'Transfer-Encoding', 'v', 'The header Transfer-Encoding is written by the generator itself.'],
            'proxy' => ['https://a.example/', 'Proxy-Authorization', 'v', 'The header Proxy-Authorization is written by the generator itself.'],
            'unclosed variable' => ['https://a.example/', 'X', 'a ${TOKEN', 'The value of the header X has a "${" that does not start a variable such as ${NAME}.'],
            'bad variable name' => ['https://a.example/', 'X', '${1A}', 'The value of the header X has a "${" that does not start a variable such as ${NAME}.'],
            'line break' => ['https://a.example/', 'X', "a\nb", 'The value of the header X must not hold control characters.'],
            'delete' => ['https://a.example/', 'X', "a\x7F", 'The value of the header X must not hold control characters.'],
        ];
    }

    /**
     * @dataProvider invalid
     */
    public function testRefusesWhatItCannotSend(string $prefix, string $name, string $value, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new RemoteHeader($prefix, $name, $value);
    }

    public function testKeepsATabInTheValue(): void
    {
        self::assertSame("a\tb", (new RemoteHeader('https://a.example/', 'X', "a\tb"))->render([]));
    }

    public function testChoosesTheLongestPrefixForEachName(): void
    {
        $settings = new RemoteRefsSettings(['https://git.example.com/'], '/cache', 10, [
            new RemoteHeader('https://git.example.com/', 'Authorization', 'outer'),
            new RemoteHeader('https://git.example.com/', 'AUTHORIZATION', 'same length, later'),
            new RemoteHeader('https://git.example.com/api/', 'authorization', 'inner'),
            new RemoteHeader('https://git.example.com/', 'X-Trace', 'one'),
            new RemoteHeader('https://other.example.com/', 'X-Other', 'no'),
            new RemoteHeader('https://git.example.com/api/v2/', 'Authorization', 'deeper'),
        ]);

        self::assertSame(['inner', 'one'], $this->values($settings->headersFor('https://git.example.com/api/v1/a.yaml')));
        self::assertSame(['outer', 'one'], $this->values($settings->headersFor('https://git.example.com/docs/a.yaml')));
        self::assertSame(['deeper', 'one'], $this->values($settings->headersFor('https://git.example.com/api/v2/a.yaml')));
        self::assertSame([], $settings->headersFor('https://elsewhere.example.com/a.yaml'));
        self::assertCount(6, $settings->headers());
        self::assertSame([], (new RemoteRefsSettings([], '/cache'))->headers());
    }

    /**
     * @param list<RemoteHeader> $headers
     *
     * @return list<string>
     */
    private function values(array $headers): array
    {
        return array_map(static fn (RemoteHeader $header): string => $header->render([]), $headers);
    }
}
