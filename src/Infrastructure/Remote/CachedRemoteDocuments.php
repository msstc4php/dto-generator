<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Infrastructure\Remote;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use MSSTC4PHP\DtoGenerator\Application\Config\RemoteHeader;
use MSSTC4PHP\DtoGenerator\Application\Port\Document;
use MSSTC4PHP\DtoGenerator\Application\Port\DocumentLoadFailed;
use MSSTC4PHP\DtoGenerator\Application\Port\RemoteDocuments;
use MSSTC4PHP\DtoGenerator\Application\Port\RemoteRequest;
use MSSTC4PHP\DtoGenerator\Infrastructure\Document\DocumentDecoder;
use MSSTC4PHP\DtoGenerator\Infrastructure\Document\UndecodableDocument;

/**
 * Remote documents kept in the cache directory of the config (spec F2 §2): `index.json` maps each URL to its file, the
 * time it was fetched (UTC) and the SHA-256 of its content, which a later run checks before reading the file. The hash
 * guards against accidents, not against whoever may commit the directory: review it like code.
 */
final class CachedRemoteDocuments implements RemoteDocuments
{
    private const MAX_BYTES = 10485760;

    private const INDEX = 'index.json';

    private const LOCK = '.index.lock';

    private const FILE = '#^[0-9a-f]{64}\.(?:json|yaml)\z#';

    private const SHA256 = '#^[0-9a-f]{64}\z#';

    private Fetcher $fetcher;

    private DocumentDecoder $decoder;

    /** @var Closure(): DateTimeImmutable */
    private Closure $clock;

    /** @var Closure(string): ?string */
    private Closure $environment;

    /**
     * @param (Closure(): DateTimeImmutable)|null $clock
     * @param (Closure(string): ?string)|null $environment the value of an environment variable, null when it is not set
     */
    public function __construct(Fetcher $fetcher, ?DocumentDecoder $decoder = null, ?Closure $clock = null, ?Closure $environment = null)
    {
        $this->fetcher = $fetcher;
        $this->decoder = $decoder ?? new DocumentDecoder();
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->environment = $environment ?? static fn (string $name): ?string => Environment::variable($name);
    }

    public function load(string $url, RemoteRequest $request): Document
    {
        return new Document($url, $this->read($url, $request));
    }

    /**
     * @return array<array-key, mixed>
     */
    private function read(string $url, RemoteRequest $request): array
    {
        $directory = $request->cacheDir();
        $entry = $this->index($url, $directory)[$url] ?? null;
        if ($entry !== null && !$request->fetchesCached()) {
            return $this->cached($url, $directory . '/' . $entry['file'], $entry['sha256']);
        }

        if (!$request->fetchesMissing()) {
            throw DocumentLoadFailed::remote($url, sprintf('is not cached in %s; run generate to fetch it.', $directory));
        }

        $headers = $this->headers($url, $request);
        try {
            $response = $this->fetcher->get($url, $request->timeout(), self::MAX_BYTES, $headers);
        } catch (FetchFailed $exception) {
            throw DocumentLoadFailed::remote($url, sprintf('could not be fetched: %s.', rtrim($exception->getMessage(), '.')));
        }

        $status = $response->status();
        $location = $response->location();
        if ($status >= 300 && $status < 400) {
            throw DocumentLoadFailed::remote($url, $location === null ? sprintf('could not be fetched: the server answered %d without a Location.', $status) : sprintf('redirects to "%s"; refer to that URL instead, and allow it.', $location));
        }

        if ($status !== 200) {
            throw DocumentLoadFailed::remote($url, sprintf('could not be fetched: the server answered %d.', $status));
        }

        $json = $this->isJson($url, $response->contentType(), $response->body());
        $decoded = $this->decode($url, $response->body(), $json);
        $this->store($url, $directory, $json, $response->body(), $request->fetchesCached());

        return $decoded;
    }

    /**
     * The headers of the request with their variables filled in; a variable that is not set stops the fetch rather than
     * send an empty credential.
     *
     * @return array<string, string> values by name
     */
    private function headers(string $url, RemoteRequest $request): array
    {
        $headers = [];
        foreach ($request->headers() as $header) {
            $variables = [];
            foreach ($header->variables() as $name) {
                $value = ($this->environment)($name);
                if ($value === null || $value === '') {
                    throw DocumentLoadFailed::remote($url, sprintf('needs the environment variable %s for its %s header, which is not set.', $name, $header->name()));
                }

                $variables[$name] = $value;
            }

            $value = $header->render($variables);
            // A line break from the environment would end the header and start another.
            if (RemoteHeader::hasControlCharacters($value)) {
                throw DocumentLoadFailed::remote($url, sprintf('cannot be fetched: the value of its %s header holds control characters.', $header->name()));
            }

            $headers[$header->name()] = $value;
        }

        return $headers;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function cached(string $url, string $path, string $sha256): array
    {
        // A link could make a committed cache read a file of the machine that runs the generator.
        $content = is_file($path) && !is_link($path) ? file_get_contents($path) : false;
        if ($content === false) {
            throw DocumentLoadFailed::remote($url, sprintf('is listed in the cache, but %s cannot be read as a plain file; run generate --refresh-remote to fetch it again.', $path));
        }

        // A cached copy is part of the build; one changed by hand must not pass for what the server sent.
        if (!hash_equals($sha256, hash('sha256', $content))) {
            throw DocumentLoadFailed::remote($url, sprintf('in the cache (%s) does not match the SHA-256 recorded when it was fetched; run generate --refresh-remote to fetch it again.', $path));
        }

        return $this->decode($url, $content, substr($path, -5) === '.json');
    }

    /**
     * Writes the document and adds it to the index under a lock, so runs sharing the cache keep each other's entries and
     * a refresh never deletes a file another run is about to list.
     *
     * @param bool $replaces whether the file of the previous entry goes: only a refresh asks for that, a run that found
     *                       the entry missing replaces one another run has just written, and a third may be reading its file
     */
    private function store(string $url, string $directory, bool $json, string $content, bool $replaces): void
    {
        // Named by content: two runs that got different answers write different files, and the index names one of them.
        $file = hash('sha256', $content) . ($json ? '.json' : '.yaml');
        $this->directory($url, $directory);
        $path = $directory . '/' . self::LOCK;
        set_error_handler(static fn (): bool => true);
        try {
            $lock = is_link($path) ? false : fopen($path, 'c');
        } finally {
            restore_error_handler();
        }

        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw DocumentLoadFailed::remote($url, sprintf('could not be cached in %s: its index cannot be locked (%s must be a plain file).', $directory, self::LOCK));
        }

        try {
            $this->write($url, $directory, $file, $content);
            $index = $this->index($url, $directory);
            $previous = $index[$url]['file'] ?? null;
            $index[$url] = ['file' => $file, 'fetchedAt' => ($this->clock)()->format('Y-m-d\TH:i:s\Z'), 'sha256' => hash('sha256', $content)];
            ksort($index);
            try {
                $encoded = json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw DocumentLoadFailed::remote($url, sprintf('could not be cached in %s: %s.', $directory, $exception->getMessage()));
            }

            $this->write($url, $directory, self::INDEX, $encoded . "\n");
            // Another URL with the same content may still need the file.
            if ($replaces && $previous !== null && !in_array($previous, array_column($index, 'file'), true)) {
                $this->remove($directory . '/' . $previous);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
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
     * By the extension of the URL's path, else a YAML content type, else by the first character: a JSON document is an
     * object. Only YAML's flow style, which a server labels as YAML, starts with "{" as well.
     */
    private function isJson(string $url, ?string $contentType, string $body): bool
    {
        $extension = strtolower(pathinfo(explode('?', $url)[0], PATHINFO_EXTENSION));
        if (in_array($extension, ['json', 'yaml', 'yml'], true)) {
            return $extension === 'json';
        }

        if ($contentType !== null && preg_match('#yaml#i', $contentType) === 1) {
            return false;
        }

        return strncmp(ltrim($body), '{', 1) === 0;
    }

    /**
     * The index, every entry checked: a merge conflict or a hand edit must stop the run, not empty the cache.
     *
     * @return array<string, array{file: string, fetchedAt: string, sha256: string}>
     */
    private function index(string $url, string $directory): array
    {
        $path = $directory . '/' . self::INDEX;
        if (is_link($path)) {
            throw DocumentLoadFailed::remote($url, sprintf('cannot use the cache: %s is a symbolic link; replace it with the file.', $path));
        }

        if (!is_file($path)) {
            return [];
        }

        try {
            $index = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw DocumentLoadFailed::remote($url, sprintf('cannot use the cache: %s is not valid JSON (%s); fix or delete it.', $path, $exception->getMessage()));
        }

        $entries = [];
        foreach (is_array($index) ? $index : [false] as $key => $entry) {
            $file = is_array($entry) ? $entry['file'] ?? null : null;
            $sha256 = is_array($entry) ? $entry['sha256'] ?? null : null;
            $fetchedAt = is_array($entry) ? $entry['fetchedAt'] ?? null : null;
            if (!is_string($key) || !is_string($file) || preg_match(self::FILE, $file) !== 1 || !is_string($sha256) || preg_match(self::SHA256, $sha256) !== 1 || !is_string($fetchedAt)) {
                throw DocumentLoadFailed::remote($url, sprintf('cannot use the cache: %s holds an entry that is not {file, fetchedAt, sha256}; fix or delete it.', $path));
            }

            $entries[$key] = ['file' => $file, 'fetchedAt' => $fetchedAt, 'sha256' => $sha256];
        }

        return $entries;
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
            $written = file_put_contents($temporary, $content) === strlen($content)
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

    private function directory(string $url, string $directory): void
    {
        $problem = null;
        set_error_handler(static function (int $level, string $message) use (&$problem): bool {
            $problem = $message;

            return true;
        });
        try {
            $made = is_dir($directory) || mkdir($directory, 0777, true);
        } finally {
            restore_error_handler();
        }

        if (!$made) {
            throw DocumentLoadFailed::remote($url, sprintf('could not be cached in %s: %s.', $directory, $problem ?? 'unknown error'));
        }
    }

    private function remove(string $path): void
    {
        set_error_handler(static fn (): bool => true);
        try {
            if (is_file($path) && !is_link($path)) {
                unlink($path);
            }
        } finally {
            restore_error_handler();
        }
    }
}
