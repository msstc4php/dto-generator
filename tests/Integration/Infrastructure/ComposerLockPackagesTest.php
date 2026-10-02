<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Integration\Infrastructure;

use FilesystemIterator;
use MSSTC4PHP\DtoGenerator\Application\Port\ProjectPackagesUnusable;
use MSSTC4PHP\DtoGenerator\Infrastructure\Environment\ComposerLockPackages;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class ComposerLockPackagesTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/dto-generator-lock-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/config/nested', 0777, true);
        file_put_contents($this->root . '/composer.json', '{}');
    }

    protected function tearDown(): void
    {
        $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) {
            assert($entry instanceof SplFileInfo);
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }

        rmdir($this->root);
    }

    public function testReadsTheVersionsOfTheNearestProjectsLock(): void
    {
        file_put_contents($this->root . '/composer.lock', json_encode([
            'packages' => [['name' => 'symfony/validator', 'version' => 'v7.1.0'], ['name' => 'psr/log', 'version' => '3.0.0']],
            'packages-dev' => [['name' => 'phpunit/phpunit', 'version' => '9.6.37']],
        ]));

        $packages = (new ComposerLockPackages())->read($this->root . '/config/nested');

        self::assertSame('v7.1.0', $packages->version('symfony/validator'));
        self::assertSame('3.0.0', $packages->version('psr/log'));
        self::assertSame('9.6.37', $packages->version('phpunit/phpunit'));
        self::assertFalse($packages->has('symfony/serializer'));
    }

    public function testAcceptsALockWithoutDevPackagesOrAnyPackages(): void
    {
        file_put_contents($this->root . '/composer.lock', '{"packages": [{"name": "psr/log", "version": "3.0.0"}], "packages-dev": null}');
        self::assertTrue((new ComposerLockPackages())->read($this->root)->has('psr/log'));

        file_put_contents($this->root . '/composer.lock', '{}');
        self::assertFalse((new ComposerLockPackages())->read($this->root)->has('psr/log'));
    }

    public function testKnowsNoPackagesWithoutALock(): void
    {
        self::assertFalse((new ComposerLockPackages())->read($this->root)->has('psr/log'));
    }

    public function testKnowsNoPackagesOutsideAComposerProject(): void
    {
        self::assertFalse((new ComposerLockPackages())->read('/')->has('psr/log'));
    }

    public function testStopsAtTheNearestProjectEvenWithoutItsLock(): void
    {
        file_put_contents($this->root . '/composer.lock', '{"packages": [{"name": "psr/log", "version": "3.0.0"}]}');
        file_put_contents($this->root . '/config/composer.json', '{}');

        self::assertFalse((new ComposerLockPackages())->read($this->root . '/config/nested')->has('psr/log'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public function unusableLocks(): iterable
    {
        yield 'invalid JSON' => ['{', 'is not valid JSON'];
        yield 'no object' => ['[1]', 'does not contain an object'];
        yield 'packages no list' => ['{"packages": "psr/log"}', 'has "packages" that is no list'];
        yield 'dev packages no list' => ['{"packages-dev": 1}', 'has "packages-dev" that is no list'];
        yield 'package no object' => ['{"packages": ["psr/log"]}', 'has a package without a name or version'];
        yield 'package without version' => ['{"packages": [{"name": "psr/log"}]}', 'has a package without a name or version'];
        yield 'package without name' => ['{"packages-dev": [{"version": "1.0.0"}]}', 'has a package without a name or version'];
        yield 'package with an empty version' => ['{"packages": [{"name": "psr/log", "version": ""}]}', 'has a package without a name or version'];
    }

    /**
     * @dataProvider unusableLocks
     */
    public function testRefusesAnUnusableLock(string $lock, string $reason): void
    {
        file_put_contents($this->root . '/composer.lock', $lock);

        try {
            (new ComposerLockPackages())->read($this->root);
            self::fail('The unusable lock went unnoticed.');
        } catch (ProjectPackagesUnusable $exception) {
            self::assertSame($this->root . '/composer.lock', $exception->file());
            self::assertSame($reason, $exception->reason());
        }
    }

    public function testRefusesAnUnreadableLock(): void
    {
        file_put_contents($this->root . '/composer.lock', '{}');
        chmod($this->root . '/composer.lock', 0000);
        if (is_readable($this->root . '/composer.lock')) {
            self::markTestSkipped('root can read any file');
        }

        try {
            $this->expectException(ProjectPackagesUnusable::class);
            $this->expectExceptionMessage('cannot be read');
            (new ComposerLockPackages())->read($this->root);
        } finally {
            chmod($this->root . '/composer.lock', 0644);
        }
    }
}
