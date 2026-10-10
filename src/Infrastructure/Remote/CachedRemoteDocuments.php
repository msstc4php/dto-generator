<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Infrastructure\Remote;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use MSSTC4PHP\DtoGenerator\Application\Config\RemoteRefsSettings;
use MSSTC4PHP\DtoGenerator\Application\Port\Document;
use MSSTC4PHP\DtoGenerator\Application\Port\DocumentLoadFailed;
use MSSTC4PHP\DtoGenerator\Application\Port\RemoteDocuments;
use MSSTC4PHP\DtoGenerator\Infrastructure\Document\DocumentDecoder;
use MSSTC4PHP\DtoGenerator\Infrastructure\Document\UndecodableDocument;

/**
 * Remote documents kept in the cache directory of the config (spec F2 §2): `index.json` maps each URL to its file, the
 * time it was fetched (UTC) and the SHA-256 of its content, which a later run checks before reading the file.
 */
final class CachedRemoteDocuments implements RemoteDocuments
{
    private const MAX_BYTES = 10485760;

    private const INDEX = 'index.json';

    private Fetcher $fetcher;

    private DocumentDecoder $decoder;

    /** @var Closure(): DateTimeImmutable */
    private Closure $clock;

    /** @var array<string, array<array-key, mixed>> decoded documents by URL */
    private array $decoded = [];

    /**
     * @param (Closure(): DateTimeImmutable)|null $clock
     */
    public function __construct(Fetcher $fetcher, ?DocumentDecoder $decoder = null, ?Closure $clock = null)
    {
        $this->fetcher = $fetcher;
        $this->decoder = $decoder ?? new DocumentDecoder();
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public function load(string $url, RemoteRefsSettings $settings, bool $fetch): Document
    {
        $this->decoded[$url] ??= $this->read($url, $settings, $fetch);

        return new Document($url, $this->decoded[$url]);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function read(string $url, RemoteRefsSettings $settings, bool $fetch): array
    {
        $directory = $settings->cacheDir();
        $index = $this->index($directory);
        $entry = $index[$url] ?? null;
        if (is_array($entry) && is_string($entry['file'] ?? null) && is_string($entry['sha256'] ?? null)) {
            return $this->cached($url, $directory . '/' . basename($entry['file']), $entry['sha256']);
        }

        if (!$fetch) {
            throw DocumentLoadFailed::remote($url, sprintf('is not cached in %s; run generate to fetch it.', $directory));
        }

        try {
            $response = $this->fetcher->get($url, $settings->timeout(), self::MAX_BYTES);
        } catch (FetchFailed $exception) {
            throw DocumentLoadFailed::remote($url, sprintf('could not be fetched: %s.', rtrim($exception->getMessage(), '.')));
        }

        if ($response->status() >= 300 && $response->status() < 400) {
            throw DocumentLoadFailed::remote($url, sprintf('redirects to "%s"; refer to that URL instead, and allow it.', (string) $response->location()));
        }

        if ($response->status() !== 200) {
            throw DocumentLoadFailed::remote($url, sprintf('could not be fetched: the server answered %d.', $response->status()));
        }

        $json = $this->isJson($url, $response->contentType(), $response->body());
        $decoded = $this->decode($url, $response->body(), $json);
        $file = hash('sha256', $url) . ($json ? '.json' : '.yaml');
        $this->write($url, $directory, $file, $response->body());
        $index[$url] = ['file' => $file, 'fetchedAt' => ($this->clock)()->format('Y-m-d\TH:i:s\Z'), 'sha256' => hash('sha256', $response->body())];
        ksort($index);
        $this->write($url, $directory, self::INDEX, json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

        return $decoded;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function cached(string $url, string $path, string $sha256): array
    {
        $content = is_file($path) ? file_get_contents($path) : false;
        if ($content === false) {
            throw DocumentLoadFailed::remote($url, sprintf('is listed in the cache, but %s cannot be read; delete its entry in %s to fetch it again.', $path, self::INDEX));
        }

        // A cached copy is part of the build; one changed by hand must not pass for what the server sent.
        if (!hash_equals($sha256, hash('sha256', $content))) {
            throw DocumentLoadFailed::remote($url, sprintf('in the cache (%s) does not match the SHA-256 recorded when it was fetched; delete it and its entry in %s to fetch it again.', $path, self::INDEX));
        }

        return $this->decode($url, $content, substr($path, -5) === '.json');
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decode(string $url, string $content, bool $json): array
    {
        try {
            return $this->decoder->decode($content, $json);
        } catch (UndecodableDocument $exception) {
            throw DocumentLoadFailed::remote($url, $exception->isMalformed() ? sprintf('is not valid: %s', $exception->getMessage()) : 'must contain an object at the top level.');
        }
    }

    /**
     * By the extension of the URL's path, else by the content type, else by the first character JSON allows.
     */
    private function isJson(string $url, ?string $contentType, string $body): bool
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (in_array($extension, ['json', 'yaml', 'yml'], true)) {
            return $extension === 'json';
        }

        if ($contentType !== null && preg_match('#[/+]json\b#i', $contentType) === 1) {
            return true;
        }

        if ($contentType !== null && preg_match('#yaml#i', $contentType) === 1) {
            return false;
        }

        return in_array(substr(ltrim($body), 0, 1), ['{', '['], true);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function index(string $directory): array
    {
        $path = $directory . '/' . self::INDEX;
        $content = is_file($path) ? file_get_contents($path) : false;
        if ($content === false) {
            return [];
        }

        try {
            $index = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            return [];
        }

        return is_array($index) ? $index : [];
    }

    /**
     * Next to the target first, then renamed over it, so a run that stops halfway leaves no truncated file.
     */
    private function write(string $url, string $directory, string $file, string $content): void
    {
        $temporary = $directory . '/.' . $file . '.' . bin2hex(random_bytes(4));
        $problem = null;
        set_error_handler(static function (int $level, string $message) use (&$problem): bool {
            $problem = $message;

            return true;
        });
        try {
            $written = (is_dir($directory) || mkdir($directory, 0777, true))
                && file_put_contents($temporary, $content) === strlen($content)
                && rename($temporary, $directory . '/' . $file);
        } finally {
            restore_error_handler();
        }

        if (!$written) {
            if (is_file($temporary)) {
                unlink($temporary);
            }

            throw DocumentLoadFailed::remote($url, sprintf('could not be cached in %s: %s.', $directory, $problem ?? 'unknown error'));
        }
    }
}
