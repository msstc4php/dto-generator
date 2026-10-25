<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Infrastructure\Document;

use Closure;
use MSSTC4PHP\DtoGenerator\Infrastructure\Remote\Environment;
use ReflectionClass;
use Symfony\Component\Yaml\Inline;
use Symfony\Component\Yaml\Parser;
use Symfony\Component\Yaml\Unescaper;
use Symfony\Component\Yaml\Yaml;

/**
 * Decoded YAML documents kept between runs in a directory only the user may enter, by the SHA-256 of the code that read
 * them and of their text: symfony/yaml takes seconds on a large spec, unserialize() a hundredth of that. An entry that
 * does not read back, as a write cut short, is a miss and goes; so does one unread for 30 days, and the least recently
 * read beyond 256 MB.
 */
final class YamlCache
{
    // Below this, reading a file costs about what parsing the text does.
    private const MIN_BYTES = 16384;

    private const MAX_AGE = 30 * 86400;

    private const MAX_TOTAL_BYTES = 256 * 1024 * 1024;

    // An entry may take this many times the bytes of its text: aliases the decoder allows expand into copies that
    // serialize() writes out in full, so 17 KB repeating a 16 KB string a hundred times would take 1.6 MB.
    private const MAX_EXPANSION = 4;

    // What serialize() spends on an entry besides the text of a string key and value, about.
    private const ENTRY_BYTES = 16;

    private const DISABLE_ENV = 'DTO_GENERATOR_YAML_CACHE';

    // The code a reading depends on: the decoder, this cache and symfony/yaml's parser. A file changed by a checkout
    // or an update changes the version, whatever Composer last recorded.
    private const READERS = [
        DocumentDecoder::class,
        ExplicitYamlKeys::class,
        RewrittenYaml::class,
        self::class,
        Yaml::class,
        Parser::class,
        Inline::class,
        Unescaper::class,
    ];

    private string $directory;

    private string $version;

    private ?int $user;

    private int $maxTotalBytes;

    /** @var Closure(): int */
    private Closure $clock;

    /**
     * @param string $version what the reading depends on besides the text
     * @param int|null $user the effective user id that must own the directories, null where it cannot be told
     * @param (Closure(): int)|null $clock the Unix time
     */
    public function __construct(string $directory, string $version, ?int $user = null, ?Closure $clock = null, int $maxTotalBytes = self::MAX_TOTAL_BYTES)
    {
        $this->directory = $directory;
        $this->version = $version;
        $this->user = $user;
        $this->clock = $clock ?? static fn (): int => time();
        $this->maxTotalBytes = $maxTotalBytes;
    }

    /**
     * The cache in the user's cache directory ($XDG_CACHE_HOME, else ~/.cache), or null when DTO_GENERATOR_YAML_CACHE is
     * 0 or neither is set. Nothing is created before the first entry.
     *
     * @param (Closure(string): ?string)|null $environment the value of an environment variable, null when it is not set
     */
    public static function forUser(?Closure $environment = null): ?self
    {
        $environment ??= static fn (string $name): ?string => Environment::variable($name);
        if ($environment(self::DISABLE_ENV) === '0') {
            return null;
        }

        $base = $environment('XDG_CACHE_HOME');
        if ($base === null || $base === '') {
            $home = $environment('HOME');
            $base = $home === null || $home === '' ? null : $home . '/.cache';
        }

        $user = function_exists('posix_geteuid') ? posix_geteuid() : null;

        return $base === null ? null : new self($base . '/dto-generator/yaml', self::version(self::readerFiles()), $user);
    }

    /**
     * @return list<string> the files of the code a reading depends on
     */
    public static function readerFiles(): array
    {
        $files = [];
        foreach (self::READERS as $class) {
            $files[] = (string) (new ReflectionClass($class))->getFileName();
        }

        return $files;
    }

    /**
     * @param list<string> $files
     */
    public static function version(array $files): string
    {
        $hashes = [PHP_VERSION_ID, PHP_INT_SIZE];
        foreach ($files as $file) {
            $hashes[] = hash_file('sha256', $file);
        }

        return hash('sha256', implode(' ', $hashes));
    }

    /**
     * @return array<array-key, mixed>|null null on a miss
     */
    public function get(string $content): ?array
    {
        if (!$this->isPrivate()) {
            return null;
        }

        $path = $this->path($content);
        // A missing entry warns, and so does one cut short; a concurrent prune may take the entry before it is touched.
        set_error_handler(static fn (): bool => true);
        $serialized = file_get_contents($path);
        $decoded = is_string($serialized) ? unserialize($serialized, ['allowed_classes' => false]) : null;
        if (is_array($decoded)) {
            touch($path, ($this->clock)());
        } elseif (is_string($serialized)) {
            unlink($path);
        }

        restore_error_handler();

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<array-key, mixed> $decoded
     */
    public function put(string $content, array $decoded): void
    {
        $budget = self::MAX_EXPANSION * strlen($content);
        if (strlen($content) < self::MIN_BYTES || !self::fits($decoded, $budget)) {
            return;
        }

        set_error_handler(static fn (): bool => true);
        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0700, true);
        }

        if ($this->isPrivate()) {
            $path = $this->path($content);
            // serialize() writes floats to serialize_precision; -1 gives them back exactly.
            $precision = ini_set('serialize_precision', '-1');
            $serialized = serialize($decoded);
            ini_set('serialize_precision', (string) $precision);
            // What a full disk leaves of an entry does not read back, and the next read drops it.
            if (file_put_contents($path, $serialized, LOCK_EX) !== false) {
                touch($path, ($this->clock)());
            }

            $this->prune();
        }

        restore_error_handler();
    }

    /**
     * Counts the size of the entry down from the budget and stops as soon as it runs out, so that no expansion costs
     * more than the budget.
     *
     * @param array<array-key, mixed> $values
     */
    private static function fits(array $values, int &$budget): bool
    {
        foreach ($values as $key => $value) {
            $budget -= self::ENTRY_BYTES + (is_string($key) ? strlen($key) : 0) + (is_string($value) ? strlen($value) : 0);
            if ($budget < 0 || (is_array($value) && !self::fits($value, $budget))) {
                return false;
            }
        }

        return true;
    }

    private function path(string $content): string
    {
        // The version is a SHA-256 of its own, so no two pairs of version and text join into the same string.
        return $this->directory . '/' . hash('sha256', $this->version . $content) . '.ser';
    }

    /**
     * Another user who could write an entry could choose what a spec reads as, and one who could read it, what a private
     * spec holds: the directory and its parent are no links, are the user's own, and no one else may use them.
     */
    private function isPrivate(): bool
    {
        foreach ([dirname($this->directory), $this->directory] as $directory) {
            if (is_link($directory) || !is_dir($directory) || !is_writable($directory) || (fileperms($directory) & 0077) !== 0
                || ($this->user !== null && fileowner($directory) !== $this->user)) {
                return false;
            }
        }

        return true;
    }

    private function prune(): void
    {
        $oldest = ($this->clock)() - self::MAX_AGE;
        $entries = [];
        foreach (array_diff((array) scandir($this->directory), ['.', '..']) as $name) {
            $path = $this->directory . '/' . $name;
            $modified = (int) filemtime($path);
            if ($modified < $oldest) {
                unlink($path);
            } else {
                $entries[$path] = [$modified, (int) filesize($path)];
            }
        }

        // The most recently read stay.
        uasort($entries, static fn (array $one, array $other): int => $other[0] <=> $one[0]);
        $total = 0;
        foreach ($entries as $path => [, $size]) {
            $total += $size;
            if ($total > $this->maxTotalBytes) {
                unlink($path);
            }
        }
    }
}
