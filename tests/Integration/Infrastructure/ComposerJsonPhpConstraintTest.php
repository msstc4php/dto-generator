<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Integration\Infrastructure;

use MSSTC4PHP\DtoGenerator\Infrastructure\Environment\ComposerJsonPhpConstraint;
use PHPUnit\Framework\TestCase;

final class ComposerJsonPhpConstraintTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/dto-generator-composer-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/nested/deeper', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (['/nested/deeper/composer.json', '/composer.json'] as $file) {
            if (is_file($this->root . $file)) {
                unlink($this->root . $file);
            }
        }

        rmdir($this->root . '/nested/deeper');
        rmdir($this->root . '/nested');
        rmdir($this->root);
    }

    public function testFindsTheNearestComposerJsonUpTheTree(): void
    {
        file_put_contents($this->root . '/composer.json', '{"require": {"php": "^8.1"}}');

        self::assertSame('^8.1', (new ComposerJsonPhpConstraint())->find($this->root . '/nested/deeper'));
    }

    public function testStopsAtTheFirstComposerJsonEvenWithoutPhp(): void
    {
        file_put_contents($this->root . '/composer.json', '{"require": {"php": "^8.1"}}');
        file_put_contents($this->root . '/nested/deeper/composer.json', '{"require": {"psr/log": "^3.0"}}');

        self::assertNull((new ComposerJsonPhpConstraint())->find($this->root . '/nested/deeper'));
    }

    public function testIgnoresInvalidJson(): void
    {
        file_put_contents($this->root . '/composer.json', '{"require":');

        self::assertNull((new ComposerJsonPhpConstraint())->find($this->root));
    }
}
