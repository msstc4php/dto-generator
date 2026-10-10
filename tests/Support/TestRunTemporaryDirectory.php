<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Support;

use Closure;
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
    /**
     * A run that has not cleaned up after this long crashed or was killed. Age is the mtime of the run directory,
     * which the suite touches all the time by creating entries in it.
     */
    private const STALE_AFTER_SECONDS = 3600;

    /**
     * Must run before anything calls sys_get_temp_dir(): PHP resolves the directory once per process.
     */
    public static function isolate(string $base): void
    {
        $previous = getenv('TMPDIR');
        $run = self::create($base . '/' . self::owner(), time());
        putenv('TMPDIR=' . $run);
        if (sys_get_temp_dir() !== $run) {
            putenv($previous === false ? 'TMPDIR' : 'TMPDIR=' . $previous);
            self::remove($run);

            throw new RuntimeException(sprintf('sys_get_temp_dir() stays %s: it was resolved before the test bootstrap, or the sys_temp_dir ini setting overrides TMPDIR.', sys_get_temp_dir()));
        }

        $pid = getmypid();
        register_shutdown_function(static function () use ($run, $pid): void {
            // A forked child shares the directory with its parent; only the parent cleans it up.
            if (getmypid() === $pid) {
                self::remove($run);
            }
        });
    }

    /**
     * A container running as root must not leave directories the developer's own runs cannot write to or sweep.
     */
    public static function owner(): string
    {
        return function_exists('posix_geteuid') ? 'u' . posix_geteuid() : 'shared';
    }

    public static function create(string $base, int $now): string
    {
        // Parallel processes race to create the base; the loser's warning means nothing.
        self::quietly(static fn (): bool => is_dir($base) || mkdir($base, 0777, true));
        $real = realpath($base);
        if ($real === false || !is_dir($real)) {
            throw new RuntimeException('Cannot create ' . $base . '.');
        }

        if (!is_writable($real)) {
            throw new RuntimeException($real . ' is not writable; remove it or run the tests as its owner.');
        }

        // Tests compare paths built from the base verbatim, so "tests/../var/tmp" must not leak into them.
        self::sweepStale($real, $now);
        $run = $real . '/run-' . getmypid() . '-' . bin2hex(random_bytes(4));
        if (!mkdir($run, 0700)) {
            throw new RuntimeException('Cannot create ' . $run . '.');
        }

        return $run;
    }

    public static function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            // Tests make files and directories read-only on purpose; the owner can still lift that.
            chmod($path, 0700);
            foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) {
                if ($entry instanceof SplFileInfo) {
                    self::remove($entry->getPathname());
                }
            }

            rmdir($path);

            return;
        }

        // A dangling symlink does not exist for file_exists().
        if (file_exists($path) || is_link($path)) {
            unlink($path);
        }
    }

    /**
     * Parallel processes (Infection starts several at once) sweep the same stale runs; whatever another one
     * removed first is no concern of this one.
     */
    private static function sweepStale(string $base, int $now): void
    {
        $runs = glob($base . '/run-*');
        foreach ($runs === false ? [] : $runs as $run) {
            self::quietly(static function () use ($run, $now): void {
                $modified = filemtime($run);
                if ($modified !== false && $modified < $now - self::STALE_AFTER_SECONDS) {
                    self::remove($run);
                }
            });
        }
    }

    /**
     * @param Closure(): (bool|void) $action
     */
    private static function quietly(Closure $action): void
    {
        set_error_handler(static fn (): bool => true);

        try {
            $action();
        } catch (UnexpectedValueException $exception) {
            // A directory vanished while being walked.
        } finally {
            restore_error_handler();
        }
    }
}
