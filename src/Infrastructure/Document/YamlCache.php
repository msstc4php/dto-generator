<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Infrastructure\Document;

use Closure;
use Composer\InstalledVersions;
use MSSTC4PHP\DtoGenerator\Infrastructure\Remote\Environment;

/**
 * Decoded YAML documents kept between runs in a directory only the user may write, by the SHA-256 of their text, with
 * the versions that read it: symfony/yaml takes seconds on a large spec, unserialize() a hundredth of that. An entry of
 * other versions, or one that does not read back, as a write cut short, is a miss; one unread for 30 days goes on the
 * next write.
 */
final class YamlCache
{
    // Below this, reading a file costs about what parsing the text does.
    private const MIN_BYTES = 16384;

    private const MAX_AGE = 2592000;

    // An entry may take this many times the bytes of its text: aliases the decoder allows expand into copies that
    // serialize() writes out in full, so 17 KB repeating a 16 KB string a hundred times would take 1.6 MB.
    private const MAX_EXPANSION = 4;

    // What serialize() spends on an entry besides the text of a string key and value, about.
    private const ENTRY_BYTES = 16;

    private const SWITCH = 'DTO_GENERATOR_YAML_CACHE';

    // How DocumentDecoder reads a text depends on its own code and on symfony/yaml.
    private const READERS = ['msstc4php/dto-generator', 'symfony/yaml'];

    private string $directory;

    private string $version;

    /** @var Closure(): int */
    private Closure $clock;

    /**
     * @param string $version what the reading depends on besides the text
     * @param (Closure(): int)|null $clock the Unix time
     */
    public function __construct(string $directory, string $version, ?Closure $clock = null)
    {
        $this->directory = $directory;
        $this->version = $version;
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * The cache of the user's cache directory, or null when DTO_GENERATOR_YAML_CACHE is 0 or no directory is the user's
     * alone.
     *
     * @param (Closure(string): ?string)|null $environment the value of an environment variable, null when it is not set
     */
    public static function forUser(?Closure $environment = null): ?self
    {
        $environment ??= static fn (string $name): ?string => Environment::variable($name);
        if ($environment(self::SWITCH) === '0') {
            return null;
        }

        $directory = self::directory($environment);

        return $directory === null ? null : new self($directory, self::version());
    }

    /**
     * The versions of the code that reads a text, commits where Composer knows them.
     */
    public static function version(): string
    {
        $versions = [];
        foreach (self::READERS as $package) {
            $versions[] = $package . '@' . (InstalledVersions::getReference($package) ?? InstalledVersions::getPrettyVersion($package));
        }

        return implode(' ', $versions);
    }

    /**
     * @return array<array-key, mixed>|null null on a miss
     */
    public function get(string $content): ?array
    {
        $path = $this->path($content);
        // A missing entry warns, and so does one cut short.
        set_error_handler(static fn (): bool => true);
        $serialized = file_get_contents($path);
        $entry = is_string($serialized) ? unserialize($serialized, ['allowed_classes' => false]) : null;
        restore_error_handler();
        if (!is_array($entry) || ($entry[0] ?? null) !== $this->version || !is_array($entry[1] ?? null)) {
            return null;
        }

        touch($path, ($this->clock)());

        return $entry[1];
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

        $path = $this->path($content);
        set_error_handler(static fn (): bool => true);
        if (file_put_contents($path, serialize([$this->version, $decoded]), LOCK_EX) !== false) {
            touch($path, ($this->clock)());
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
        return $this->directory . '/' . hash('sha256', $content) . '.ser';
    }

    private function prune(): void
    {
        $oldest = ($this->clock)() - self::MAX_AGE;
        $entries = glob($this->directory . '/*.ser');
        foreach (is_array($entries) ? $entries : [] as $entry) {
            if (filemtime($entry) < $oldest) {
                unlink($entry);
            }
        }
    }

    /**
     * @param Closure(string): ?string $environment
     */
    private static function directory(Closure $environment): ?string
    {
        $base = $environment('XDG_CACHE_HOME');
        if ($base === null || $base === '') {
            $home = $environment('HOME');
            $base = $home === null || $home === '' ? $environment('LOCALAPPDATA') : $home . '/.cache';
        }

        return $base === null || $base === '' ? null : self::privateDirectory($base . '/dto-generator/yaml');
    }

    private static function privateDirectory(string $directory): ?string
    {
        set_error_handler(static fn (): bool => true);
        if (!is_dir($directory)) {
            mkdir($directory, 0700, true);
        }

        restore_error_handler();

        return self::isPrivate($directory) ? $directory : null;
    }

    /**
     * Another user who could write an entry could choose what a spec reads as.
     */
    private static function isPrivate(string $directory): bool
    {
        return is_dir($directory) && is_writable($directory) && (fileperms($directory) & 0022) === 0;
    }
}
