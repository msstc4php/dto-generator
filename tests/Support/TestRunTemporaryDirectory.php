<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Support;

use FilesystemIterator;
use RuntimeException;
use SplFileInfo;
use UnexpectedValueException;

/**
 * Gives each PHPUnit process its own temporary directory, so the lock files and scratch trees the suite creates
 * (Infection starts a process per mutant) never pile up in the shared /tmp.
 */
final class TestRunTemporaryDirectory
{
    /** A run that has not cleaned up after this long crashed or was killed. */
    private const STALE_AFTER_SECONDS = 3600;

    /**
     * Must run before anything calls sys_get_temp_dir(): PHP resolves the directory once per process.
     */
    public static function isolate(string $base): void
    {
        $previous = getenv('TMPDIR');
        $run = self::create($base, time());
        putenv('TMPDIR=' . $run);
        if (sys_get_temp_dir() !== $run) {
            putenv($previous === false ? 'TMPDIR' : 'TMPDIR=' . $previous);
            self::remove($run);

            throw new RuntimeException('sys_get_temp_dir() was resolved before the test bootstrap; it stays ' . sys_get_temp_dir() . '.');
        }

        $pid = getmypid();
        register_shutdown_function(static function () use ($run, $pid): void {
            // A forked child shares the directory with its parent; only the parent cleans it up.
            if (getmypid() === $pid) {
                self::remove($run);
            }
        });
    }

    public static function create(string $base, int $now): string
    {
        if (!is_dir($base) && !mkdir($base, 0777, true) && !is_dir($base)) {
            throw new RuntimeException('Cannot create ' . $base . '.');
        }

        // Tests compare paths built from it verbatim, so "tests/../var/tmp" must not leak into them.
        $real = realpath($base);
        if ($real === false) {
            throw new RuntimeException('Cannot resolve ' . $base . '.');
        }

        $base = $real;

        $runs = glob($base . '/run-*');
        foreach ($runs === false ? [] : $runs as $run) {
            if (filemtime($run) < $now - self::STALE_AFTER_SECONDS) {
                self::removeStale($run);
            }
        }

        $run = $base . '/run-' . getmypid() . '-' . bin2hex(random_bytes(4));
        if (!mkdir($run, 0700)) {
            throw new RuntimeException('Cannot create ' . $run . '.');
        }

        return $run;
    }

    /**
     * Parallel processes (Infection starts several at once) may sweep the same stale run; whatever another one
     * removed first is no concern of this one.
     */
    private static function removeStale(string $path): void
    {
        set_error_handler(static fn (): bool => true);

        try {
            self::remove($path);
        } catch (UnexpectedValueException $exception) {
            // The directory vanished while being walked.
        } finally {
            restore_error_handler();
        }
    }

    public static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        // Tests make files and directories read-only on purpose; the owner can still lift that.
        chmod($path, 0700);
        foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) {
            if ($entry instanceof SplFileInfo) {
                self::remove($entry->getPathname());
            }
        }

        rmdir($path);
    }
}
