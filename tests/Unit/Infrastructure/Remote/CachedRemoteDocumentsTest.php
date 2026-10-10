<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Infrastructure\Remote;

use DateTimeImmutable;
use MSSTC4PHP\DtoGenerator\Application\Port\DocumentLoadFailed;
use MSSTC4PHP\DtoGenerator\Infrastructure\Remote\CachedRemoteDocuments;
use MSSTC4PHP\DtoGenerator\Infrastructure\Remote\FetchFailed;
use MSSTC4PHP\DtoGenerator\Infrastructure\Remote\Response;
use MSSTC4PHP\DtoGenerator\Tests\Support\CallbackFetcher;
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

        self::assertSame(['Money' => ['type' => 'string']], $first->load(self::URL, $this->cache, 7, true)->root());
        self::assertSame(['Money' => ['type' => 'string']], $first->load(self::URL, $this->cache, 7, true)->root(), 'kept for the run');
        self::assertCount(1, $this->requests());
        self::assertSame([self::URL, 7, 10485760], $this->requests()[0]);

        $file = hash('sha256', "Money: {type: string}\n") . '.yaml';
        self::assertSame("Money: {type: string}\n", file_get_contents($this->cache . '/' . $file));
        self::assertSame(
            "{\n    \"" . self::URL . "\": {\n        \"file\": \"" . $file . "\",\n        \"fetchedAt\": \"2026-10-10T12:00:00Z\",\n        \"sha256\": \"" . hash('sha256', "Money: {type: string}\n") . "\"\n    }\n}\n",
            file_get_contents($this->cache . '/index.json'),
        );
        self::assertSame([$file, 'index.json'], $this->listed());
        self::assertFileExists($this->cache . '/.index.lock');

        $later = $this->documents(new FetchFailed('offline'));
        self::assertSame(self::URL, $later->load(self::URL, $this->cache, 7, false)->path());
        self::assertCount(1, $this->requests());
    }

    public function testKeepsTheIndexOfEveryDocument(): void
    {
        $documents = $this->documents(new Response(200, null, null, '{"A": {}}'), new Response(200, null, null, '{"B": {}}'));

        $documents->load('https://example.com/b.json', $this->cache, 7, true);
        $documents->load('https://example.com/a.json', $this->cache, 7, true);

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
            'yaml type' => ['https://example.com/a', 'Application/YAML; charset=utf-8', '{A: {}}', '.yaml'],
            'json labelled as text' => ['https://example.com/a', 'text/plain', '{"A": {}}', '.json'],
            'json content' => ['https://example.com/a?v=1.yaml', 'text/plain', ' {"A": {}}', '.json'],
            'yaml content' => ['https://example.com/a', null, 'A: {}', '.yaml'],
        ];
    }

    /**
     * @dataProvider formats
     */
    public function testReadsTheFormatFromTheUrlTheTypeOrTheContent(string $url, ?string $type, string $body, string $extension): void
    {
        $documents = $this->documents(new Response(200, $type, null, $body));

        self::assertSame(['A' => []], $documents->load($url, $this->cache, 7, true)->root());
        self::assertSame([hash('sha256', $body) . $extension, 'index.json'], $this->listed());
    }

    /**
     * @return array<string, array{Response|FetchFailed, string}>
     */
    public static function failures(): array
    {
        return [
            'redirect' => [new Response(301, null, 'https://cdn.example.com/money.yaml', ''), 'redirects to "https://cdn.example.com/money.yaml"; refer to that URL instead, and allow it.'],
            'redirect without location' => [new Response(302, null, null, ''), 'could not be fetched: the server answered 302 without a Location.'],
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
        try {
            $this->documents($answer)->load(self::URL, $this->cache, 7, true);
            self::fail('No failure');
        } catch (DocumentLoadFailed $exception) {
            self::assertStringStartsWith('Remote document "' . self::URL . '" ' . $problem, $exception->getMessage());
            self::assertStringEndsWith('.', $exception->getMessage());
            self::assertStringEndsNotWith('..', $exception->getMessage());
        }

        self::assertFalse(is_file($this->cache . '/index.json'));
    }

    public function testReadsNothingButTheCacheWhenItMayNotFetch(): void
    {
        $this->expectException(DocumentLoadFailed::class);
        $this->expectExceptionMessage('Remote document "' . self::URL . '" is not cached in ' . $this->cache . '; run generate to fetch it.');

        try {
            $this->documents(new Response(200, null, null, 'A: {}'))->load(self::URL, $this->cache, 7, false);
        } finally {
            self::assertSame([], $this->requests());
        }
    }

    public function testRefusesACachedCopyChangedByHand(): void
    {
        $this->documents(new Response(200, null, null, 'A: {}'))->load(self::URL, $this->cache, 7, true);
        $file = $this->cache . '/' . hash('sha256', 'A: {}') . '.yaml';
        file_put_contents($file, 'A: {type: integer}');

        $this->expectException(DocumentLoadFailed::class);
        $this->expectExceptionMessage('Remote document "' . self::URL . '" in the cache (' . $file . ') does not match the SHA-256 recorded when it was fetched; delete it and its entry in index.json to fetch it again.');

        $this->documents()->load(self::URL, $this->cache, 7, true);
    }

    public function testReportsACachedCopyThatIsGone(): void
    {
        $this->documents(new Response(200, null, null, 'A: {}'))->load(self::URL, $this->cache, 7, true);
        $file = $this->cache . '/' . hash('sha256', 'A: {}') . '.yaml';
        unlink($file);

        $this->expectException(DocumentLoadFailed::class);
        $this->expectExceptionMessage('Remote document "' . self::URL . '" is listed in the cache, but ' . $file . ' cannot be read as a plain file; delete its entry in index.json to fetch it again.');

        $this->documents()->load(self::URL, $this->cache, 7, true);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function brokenIndexes(): array
    {
        $entry = ['file' => str_repeat('a', 64) . '.yaml', 'fetchedAt' => '2026-10-10T12:00:00Z', 'sha256' => str_repeat('b', 64)];

        return [
            'merge conflict' => ["<<<<<<< HEAD\n{}\n=======\n{}\n>>>>>>> theirs\n", 'is not valid JSON (Syntax error); fix or delete it.'],
            'not an object' => ['"x"', 'holds an entry that is not {file, fetchedAt, sha256}; fix or delete it.'],
            'entry not an object' => [(string) json_encode(['https://a/' => 'x']), 'holds an entry that is not {file, fetchedAt, sha256}; fix or delete it.'],
            'numeric key' => [(string) json_encode([$entry]), 'holds an entry that is not {file, fetchedAt, sha256}; fix or delete it.'],
            'file outside the cache' => [(string) json_encode(['https://a/' => ['file' => '../../etc/passwd'] + $entry]), 'holds an entry that is not {file, fetchedAt, sha256}; fix or delete it.'],
            'file without its suffix' => [(string) json_encode(['https://a/' => ['file' => str_repeat('a', 64) . '.yaml.bak'] + $entry]), 'holds an entry that is not {file, fetchedAt, sha256}; fix or delete it.'],
            'file with more' => [(string) json_encode(['https://a/' => ['file' => 'x' . str_repeat('a', 64) . '.json'] + $entry]), 'holds an entry that is not {file, fetchedAt, sha256}; fix or delete it.'],
            'file of another type' => [(string) json_encode(['https://a/' => ['file' => 5] + $entry]), 'holds an entry that is not {file, fetchedAt, sha256}; fix or delete it.'],
            'short hash' => [(string) json_encode(['https://a/' => ['sha256' => 'b'] + $entry]), 'holds an entry that is not {file, fetchedAt, sha256}; fix or delete it.'],
            'hash with more' => [(string) json_encode(['https://a/' => ['sha256' => str_repeat('b', 64) . "\n"] + $entry]), 'holds an entry that is not {file, fetchedAt, sha256}; fix or delete it.'],
            'hash of another type' => [(string) json_encode(['https://a/' => ['sha256' => 1] + $entry]), 'holds an entry that is not {file, fetchedAt, sha256}; fix or delete it.'],
            'no time' => [(string) json_encode(['https://a/' => ['fetchedAt' => null] + $entry]), 'holds an entry that is not {file, fetchedAt, sha256}; fix or delete it.'],
        ];
    }

    /**
     * @dataProvider brokenIndexes
     */
    public function testStopsAtAnIndexItCannotTrust(string $index, string $problem): void
    {
        mkdir($this->cache, 0777, true);
        file_put_contents($this->cache . '/index.json', $index);

        $this->expectException(DocumentLoadFailed::class);
        $this->expectExceptionMessage('Remote document "' . self::URL . '" cannot use the cache: ' . $this->cache . '/index.json ' . $problem);

        $this->documents(new Response(200, null, null, 'A: {}'))->load(self::URL, $this->cache, 7, true);
    }

    public function testTakesAnEmptyIndex(): void
    {
        mkdir($this->cache, 0777, true);
        file_put_contents($this->cache . '/index.json', '{}');

        self::assertSame(['A' => []], $this->documents(new Response(200, null, null, 'A: {}'))->load(self::URL, $this->cache, 7, true)->root());
    }

    public function testRefusesACachedCopyThatIsALink(): void
    {
        $this->documents(new Response(200, null, null, 'A: {}'))->load(self::URL, $this->cache, 7, true);
        $file = $this->cache . '/' . hash('sha256', 'A: {}') . '.yaml';
        rename($file, $this->cache . '/target.yaml');
        symlink($this->cache . '/target.yaml', $file);

        $this->expectException(DocumentLoadFailed::class);
        $this->expectExceptionMessage('cannot be read as a plain file');

        $this->documents()->load(self::URL, $this->cache, 7, true);
    }

    public function testRefusesAnIndexOrALockThatIsALink(): void
    {
        mkdir($this->cache, 0777, true);
        file_put_contents($this->cache . '/elsewhere.json', '{}');
        symlink($this->cache . '/elsewhere.json', $this->cache . '/index.json');

        try {
            $this->documents(new Response(200, null, null, 'A: {}'))->load(self::URL, $this->cache, 7, true);
            self::fail('No failure');
        } catch (DocumentLoadFailed $exception) {
            self::assertSame('Remote document "' . self::URL . '" cannot use the cache: ' . $this->cache . '/index.json is a symbolic link; replace it with the file.', $exception->getMessage());
        }

        unlink($this->cache . '/index.json');
        symlink(dirname($this->cache) . '/victim', $this->cache . '/.index.lock');
        $this->expectException(DocumentLoadFailed::class);
        $this->expectExceptionMessage('could not be cached in ' . $this->cache . ': its index cannot be locked (.index.lock must be a plain file).');

        try {
            $this->documents(new Response(200, null, null, 'A: {}'))->load(self::URL, $this->cache, 7, true);
        } finally {
            self::assertFileDoesNotExist(dirname($this->cache) . '/victim');
        }
    }

    public function testKeepsTheAnswersOfRacingRunsApart(): void
    {
        $cache = $this->cache;
        // The other run fetches and records B while this one waits for A.
        $racing = new CachedRemoteDocuments(new CallbackFetcher(static function () use ($cache): Response {
            (new CachedRemoteDocuments(new RecordingFetcher([new Response(200, null, null, 'B: {}')])))->load(self::URL, $cache, 7, true);

            return new Response(200, null, null, 'A: {}');
        }));

        self::assertSame(['A' => []], $racing->load(self::URL, $this->cache, 7, true)->root());
        self::assertSame(['A' => []], $this->documents()->load(self::URL, $this->cache, 7, false)->root());
        $files = [hash('sha256', 'A: {}') . '.yaml', hash('sha256', 'B: {}') . '.yaml', 'index.json'];
        sort($files);
        self::assertSame($files, $this->listed());
    }

    public function testKeepsTheDocumentsOfEachCacheApart(): void
    {
        $documents = $this->documents(new Response(200, null, null, 'A: {}'), new Response(200, null, null, 'B: {}'));

        self::assertSame(['A' => []], $documents->load(self::URL, $this->cache, 7, true)->root());
        self::assertSame(['B' => []], $documents->load(self::URL, $this->cache . '-other', 7, true)->root());
        TestRunTemporaryDirectory::remove($this->cache . '-other');
    }

    public function testReportsACacheItCannotWrite(): void
    {
        mkdir(dirname($this->cache), 0777, true);
        file_put_contents($this->cache, 'a file where the directory should be');

        $this->expectException(DocumentLoadFailed::class);
        $this->expectExceptionMessageMatches('~^Remote document "' . preg_quote(self::URL, '~') . '" could not be cached in ' . preg_quote($this->cache, '~') . ': mkdir\(\): File exists\.$~');

        $this->documents(new Response(200, null, null, 'A: {}'))->load(self::URL, $this->cache, 7, true);
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

    /**
     * @return list<string>
     */
    private function listed(): array
    {
        $names = array_values(array_filter((array) scandir($this->cache), static fn ($name): bool => strncmp((string) $name, '.', 1) !== 0));
        sort($names);

        return array_map('strval', $names);
    }
}
