<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Infrastructure\Remote;

use DateTimeImmutable;
use MSSTC4PHP\DtoGenerator\Application\Config\RemoteRefsSettings;
use MSSTC4PHP\DtoGenerator\Application\Port\DocumentLoadFailed;
use MSSTC4PHP\DtoGenerator\Infrastructure\Remote\CachedRemoteDocuments;
use MSSTC4PHP\DtoGenerator\Infrastructure\Remote\FetchFailed;
use MSSTC4PHP\DtoGenerator\Infrastructure\Remote\Response;
use MSSTC4PHP\DtoGenerator\Tests\Support\RecordingFetcher;
use MSSTC4PHP\DtoGenerator\Tests\Support\TestRunTemporaryDirectory;
use PHPUnit\Framework\TestCase;

final class CachedRemoteDocumentsTest extends TestCase
{
    private const URL = 'https://schemas.example.com/common/money.yaml';

    private string $cache;

    /** @var list<RecordingFetcher> */
    private array $fetchers = [];

    protected function setUp(): void
    {
        $this->cache = sys_get_temp_dir() . '/remote-' . bin2hex(random_bytes(4)) . '/cache';
    }

    protected function tearDown(): void
    {
        TestRunTemporaryDirectory::remove(dirname($this->cache));
    }

    public function testFetchesADocumentIntoTheCacheAndReadsItFromThereNextTime(): void
    {
        $first = $this->documents(new Response(200, 'application/yaml', null, "Money: {type: string}\n"));

        self::assertSame(['Money' => ['type' => 'string']], $first->load(self::URL, $this->settings(), true)->root());
        self::assertSame(['Money' => ['type' => 'string']], $first->load(self::URL, $this->settings(), true)->root(), 'kept for the run');
        self::assertCount(1, $this->requests());
        self::assertSame([self::URL, 7, 10485760], $this->requests()[0]);

        $file = hash('sha256', self::URL) . '.yaml';
        self::assertSame("Money: {type: string}\n", file_get_contents($this->cache . '/' . $file));
        self::assertSame(
            "{\n    \"" . self::URL . "\": {\n        \"file\": \"" . $file . "\",\n        \"fetchedAt\": \"2026-10-10T12:00:00Z\",\n        \"sha256\": \"" . hash('sha256', "Money: {type: string}\n") . "\"\n    }\n}\n",
            file_get_contents($this->cache . '/index.json'),
        );
        self::assertSame([$file, 'index.json'], $this->listed());

        $later = $this->documents(new FetchFailed('offline'));
        self::assertSame(self::URL, $later->load(self::URL, $this->settings(), false)->path());
        self::assertCount(1, $this->requests());
    }

    public function testKeepsTheIndexOfEveryDocument(): void
    {
        $documents = $this->documents(new Response(200, null, null, '{"A": {}}'), new Response(200, null, null, '{"B": {}}'));

        $documents->load('https://example.com/b.json', $this->settings(), true);
        $documents->load('https://example.com/a.json', $this->settings(), true);

        $index = json_decode((string) file_get_contents($this->cache . '/index.json'), true);

        self::assertIsArray($index);
        self::assertSame(['https://example.com/a.json', 'https://example.com/b.json'], array_keys($index));
    }

    /**
     * @return array<string, array{string, ?string, string, string}>
     */
    public static function formats(): array
    {
        return [
            'json extension' => ['https://example.com/a.json', 'text/plain', '{"A": {}}', '.json'],
            'yml extension' => ['https://example.com/a.yml', 'application/json', 'A: {}', '.yaml'],
            'json type' => ['https://example.com/a', 'application/schema+json; charset=utf-8', '{"A": {}}', '.json'],
            'yaml type' => ['https://example.com/a', 'application/yaml', 'A: {}', '.yaml'],
            'json content' => ['https://example.com/a?v=1', 'text/plain', ' {"A": {}}', '.json'],
            'yaml content' => ['https://example.com/a', null, 'A: {}', '.yaml'],
        ];
    }

    /**
     * @dataProvider formats
     */
    public function testReadsTheFormatFromTheUrlTheTypeOrTheContent(string $url, ?string $type, string $body, string $extension): void
    {
        $documents = $this->documents(new Response(200, $type, null, $body));

        self::assertSame(['A' => []], $documents->load($url, $this->settings(), true)->root());
        self::assertSame([hash('sha256', $url) . $extension, 'index.json'], $this->listed());
    }

    /**
     * @return array<string, array{Response|FetchFailed, string}>
     */
    public static function failures(): array
    {
        return [
            'redirect' => [new Response(301, null, 'https://cdn.example.com/money.yaml', ''), 'redirects to "https://cdn.example.com/money.yaml"; refer to that URL instead, and allow it.'],
            'redirect without location' => [new Response(302, null, null, ''), 'redirects to ""; refer to that URL instead, and allow it.'],
            'last redirect status' => [new Response(399, null, 'https://x/', ''), 'redirects to "https://x/"; refer to that URL instead, and allow it.'],
            'not found' => [new Response(404, null, null, 'nope'), 'could not be fetched: the server answered 404.'],
            'success of another kind' => [new Response(204, null, null, ''), 'could not be fetched: the server answered 204.'],
            'below the redirects' => [new Response(299, null, null, ''), 'could not be fetched: the server answered 299.'],
            'above the redirects' => [new Response(400, null, null, ''), 'could not be fetched: the server answered 400.'],
            'no answer' => [new FetchFailed('Connection refused.'), 'could not be fetched: Connection refused.'],
            'malformed' => [new Response(200, 'application/json', null, '{'), 'is not valid: Malformed inline YAML string'],
            'not an object' => [new Response(200, 'application/json', null, '[1]'), 'must contain an object at the top level.'],
        ];
    }

    /**
     * @dataProvider failures
     *
     * @param Response|FetchFailed $answer
     */
    public function testReportsADocumentItCannotFetch($answer, string $problem): void
    {
        $this->expectException(DocumentLoadFailed::class);
        $this->expectExceptionMessage('Remote document "' . self::URL . '" ' . $problem);

        try {
            $this->documents($answer)->load(self::URL, $this->settings(), true);
        } finally {
            self::assertFalse(is_file($this->cache . '/index.json'));
        }
    }

    public function testReadsNothingButTheCacheWhenItMayNotFetch(): void
    {
        $this->expectException(DocumentLoadFailed::class);
        $this->expectExceptionMessage('Remote document "' . self::URL . '" is not cached in ' . $this->cache . '; run generate to fetch it.');

        try {
            $this->documents(new Response(200, null, null, 'A: {}'))->load(self::URL, $this->settings(), false);
        } finally {
            self::assertSame([], $this->requests());
        }
    }

    public function testRefusesACachedCopyChangedByHand(): void
    {
        $this->documents(new Response(200, null, null, 'A: {}'))->load(self::URL, $this->settings(), true);
        $file = $this->cache . '/' . hash('sha256', self::URL) . '.yaml';
        file_put_contents($file, 'A: {type: integer}');

        $this->expectException(DocumentLoadFailed::class);
        $this->expectExceptionMessage('Remote document "' . self::URL . '" in the cache (' . $file . ') does not match the SHA-256 recorded when it was fetched; delete it and its entry in index.json to fetch it again.');

        $this->documents()->load(self::URL, $this->settings(), true);
    }

    public function testReportsACachedCopyThatIsGone(): void
    {
        $this->documents(new Response(200, null, null, 'A: {}'))->load(self::URL, $this->settings(), true);
        $file = $this->cache . '/' . hash('sha256', self::URL) . '.yaml';
        unlink($file);

        $this->expectException(DocumentLoadFailed::class);
        $this->expectExceptionMessage('Remote document "' . self::URL . '" is listed in the cache, but ' . $file . ' cannot be read; delete its entry in index.json to fetch it again.');

        $this->documents()->load(self::URL, $this->settings(), true);
    }

    public function testFetchesAgainWhenTheIndexIsUnreadable(): void
    {
        mkdir($this->cache, 0777, true);
        file_put_contents($this->cache . '/index.json', '{');

        self::assertSame(['A' => []], $this->documents(new Response(200, null, null, 'A: {}'))->load(self::URL, $this->settings(), true)->root());
        self::assertCount(1, $this->requests());
    }

    public function testIgnoresAnIndexEntryWithoutItsFile(): void
    {
        mkdir($this->cache, 0777, true);
        file_put_contents($this->cache . '/index.json', (string) json_encode([self::URL => ['sha256' => 'x'], 'other' => 'x']));

        self::assertSame(['A' => []], $this->documents(new Response(200, null, null, 'A: {}'))->load(self::URL, $this->settings(), true)->root());
    }

    public function testReportsACacheItCannotWrite(): void
    {
        mkdir(dirname($this->cache), 0777, true);
        file_put_contents($this->cache, 'a file where the directory should be');

        $this->expectException(DocumentLoadFailed::class);
        $this->expectExceptionMessageMatches('~^Remote document "' . preg_quote(self::URL, '~') . '" could not be cached in ' . preg_quote($this->cache, '~') . ': .+\.$~');

        $this->documents(new Response(200, null, null, 'A: {}'))->load(self::URL, $this->settings(), true);
    }

    /**
     * @param Response|FetchFailed ...$answers in order
     */
    private function documents(...$answers): CachedRemoteDocuments
    {
        $this->fetchers[] = $fetcher = new RecordingFetcher($answers);

        return new CachedRemoteDocuments($fetcher, null, static fn (): DateTimeImmutable => new DateTimeImmutable('2026-10-10T12:00:00Z'));
    }

    /**
     * @return list<array{string, int, int}> the requests of every fetcher so far
     */
    private function requests(): array
    {
        $requests = [];
        foreach ($this->fetchers as $fetcher) {
            $requests = array_merge($requests, $fetcher->requests());
        }

        return $requests;
    }

    private function settings(): RemoteRefsSettings
    {
        return new RemoteRefsSettings(['https://'], $this->cache, 7);
    }

    /**
     * @return list<string>
     */
    private function listed(): array
    {
        $names = array_values(array_diff((array) scandir($this->cache), ['.', '..']));
        sort($names);

        return array_map('strval', $names);
    }
}
