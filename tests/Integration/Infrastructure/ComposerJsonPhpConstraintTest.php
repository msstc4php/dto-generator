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
        $requirement = (new ComposerJsonPhpConstraint())->find($this->root . '/nested/deeper');

        self::assertSame('^8.1', $requirement->constraint());
        self::assertNull($requirement->problem());
        self::assertSame($this->root . '/composer.json', $requirement->file());
    }

    public function testStopsAtTheFirstComposerJsonEvenWithoutPhp(): void
    {
        file_put_contents($this->root . '/composer.json', '{"require": {"php": "^8.1"}}');
        file_put_contents($this->root . '/nested/deeper/composer.json', '{"require": {"psr/log": "^3.0"}}');
        $requirement = (new ComposerJsonPhpConstraint())->find($this->root . '/nested/deeper');

        self::assertNull($requirement->constraint());
        self::assertNull($requirement->problem());
    }

    public function testReportsNoFileWhenThereIsNone(): void
    {
        $requirement = (new ComposerJsonPhpConstraint())->find($this->root . '/nested/deeper');

        // The search may reach a composer.json above the temp directory; it must not be ours.
        self::assertStringNotContainsString($this->root, (string) $requirement->file());
    }

    /**
     * @dataProvider brokenFiles
     */
    public function testExplainsWhyAComposerJsonIsUnusable(string $content, string $problem): void
    {
        file_put_contents($this->root . '/composer.json', $content);
        $requirement = (new ComposerJsonPhpConstraint())->find($this->root);

        self::assertNull($requirement->constraint());
        self::assertNotNull($requirement->problem());
        self::assertStringContainsString($problem, $requirement->problem());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function brokenFiles(): array
    {
        return [
            'invalid json' => ['{"require":', 'is not valid JSON'],
            'empty file' => ['', 'is not valid JSON'],
            'scalar root' => ['"x"', 'must contain an object'],
            'numeric php' => ['{"require": {"php": 8.1}}', '"require.php" must be a string'],
        ];
    }

    public function testAcceptsAnEmptyObject(): void
    {
        file_put_contents($this->root . '/composer.json', '{}');
        $requirement = (new ComposerJsonPhpConstraint())->find($this->root);

        self::assertNull($requirement->constraint());
        self::assertNull($requirement->problem());
    }

    public function testExplainsAnUnreadableComposerJson(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('root can read any file');
        }

        file_put_contents($this->root . '/composer.json', '{"require": {"php": "^8.1"}}');
        chmod($this->root . '/composer.json', 0000);

        try {
            $requirement = (new ComposerJsonPhpConstraint())->find($this->root);
            self::assertNull($requirement->constraint());
            self::assertSame('cannot be read', $requirement->problem());
        } finally {
            chmod($this->root . '/composer.json', 0644);
        }
    }
}
