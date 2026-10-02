<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Infrastructure\Writer;

/**
 * Exclusive locks on output directories, shared by every writer of one process: flock() locks per open file, so
 * a second writer in the same process taking its own lock would wait for itself forever.
 */
final class DirectoryLocks
{
    /** @var array<string, array{resource, int}> lock path → handle and number of holders in this process */
    private static array $held = [];

    /** @var list<string> */
    private array $mine = [];

    public function __destruct()
    {
        $this->release();
    }

    /**
     * Outside the output directory, so the lock never shows up as output; keyed by the real path when it exists,
     * so a directory reached through a symlink is the same lock.
     */
    public static function path(string $directory): string
    {
        $real = realpath($directory);

        return sys_get_temp_dir() . '/dto-generator-' . sha1($real !== false ? $real : rtrim($directory, '/')) . '.lock';
    }

    /**
     * Best effort: a lock file that cannot be opened (another user's file in a shared /tmp) is skipped.
     *
     * @param list<string> $directories
     */
    public function acquire(array $directories): void
    {
        $paths = array_map([self::class, 'path'], $directories);
        // One global order, so two processes locking the same directories never wait for each other in a cycle.
        sort($paths, SORT_STRING);
        foreach (array_unique($paths) as $path) {
            if (isset(self::$held[$path])) {
                self::$held[$path] = [self::$held[$path][0], self::$held[$path][1] + 1];
                $this->mine[] = $path;

                continue;
            }

            set_error_handler(static fn (): bool => true);
            $handle = fopen($path, 'c');
            restore_error_handler();
            if ($handle !== false && flock($handle, LOCK_EX)) {
                self::$held[$path] = [$handle, 1];
                $this->mine[] = $path;
            }
        }
    }

    public function release(): void
    {
        foreach ($this->mine as $path) {
            [$handle, $holders] = self::$held[$path];
            if ($holders > 1) {
                self::$held[$path] = [$handle, $holders - 1];

                continue;
            }

            flock($handle, LOCK_UN);
            fclose($handle);
            unset(self::$held[$path]);
        }

        $this->mine = [];
    }
}
