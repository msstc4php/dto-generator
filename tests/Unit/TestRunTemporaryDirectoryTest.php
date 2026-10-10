<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit;

use MSSTC4PHP\DtoGenerator\Tests\Support\TestRunTemporaryDirectory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TestRunTemporaryDirectoryTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/runs-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        TestRunTemporaryDirectory::remove($this->base);
    }

    public function testTheSuiteRunsInsideItsOwnDirectoryUnderVar(): void
    {
        $base = realpath(dirname(__DIR__, 2) . '/var/tmp');

        self::assertSame($base, dirname(sys_get_temp_dir()));
        self::assertStringStartsWith('run-' . getmypid() . '-', basename(sys_get_temp_dir()));
    }

    public function testCreatesAFreshDirectoryPerRun(): void
    {
        $first = TestRunTemporaryDirectory::create($this->base, time());
        $second = TestRunTemporaryDirectory::create($this->base, time());

        self::assertDirectoryExists($first);
        self::assertDirectoryExists($second);
        self::assertNotSame($first, $second);
        self::assertSame($this->base, dirname($first));
    }

    public function testRemovesRunsLeftBehindForMoreThanAnHour(): void
    {
        $now = time();
        $stale = $this->base . '/run-1-stale';
        $recent = $this->base . '/run-2-recent';
        $foreign = $this->base . '/keep';
        foreach ([$stale . '/nested', $recent, $foreign] as $directory) {
            mkdir($directory, 0777, true);
        }

        touch($stale . '/nested/file.lock');
        touch($stale, $now - 3601);
        // A mutant of the lock path can drop the separator and leave a file next to the runs.
        $strayFile = $this->base . '/run-3-strayfile.lock';
        touch($strayFile, $now - 3601);
        touch($recent, $now - 3599);
        touch($foreign, $now - 7200);

        TestRunTemporaryDirectory::create($this->base, $now);

        self::assertDirectoryDoesNotExist($stale);
        self::assertFileDoesNotExist($strayFile);
        self::assertDirectoryExists($recent);
        self::assertDirectoryExists($foreign);
    }

    public function testRemoveDeletesTheTreeIncludingReadOnlyFilesAndSymlinks(): void
    {
        $run = TestRunTemporaryDirectory::create($this->base, time());
        mkdir($run . '/a/b', 0777, true);
        file_put_contents($run . '/a/b/file', 'x');
        chmod($run . '/a/b/file', 0444);
        chmod($run . '/a/b', 0555);
        $outside = $this->base . '/outside';
        mkdir($outside);
        symlink($outside, $run . '/link');

        TestRunTemporaryDirectory::remove($run);

        self::assertFileDoesNotExist($run);
        self::assertDirectoryExists($outside);
    }

    public function testRemoveIgnoresAMissingDirectory(): void
    {
        TestRunTemporaryDirectory::remove($this->base . '/missing');

        self::assertDirectoryDoesNotExist($this->base . '/missing');
    }

    public function testIsolateFailsWhenTheTemporaryDirectoryWasAlreadyResolved(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('sys_get_temp_dir()');

        TestRunTemporaryDirectory::isolate($this->base);
    }
}
