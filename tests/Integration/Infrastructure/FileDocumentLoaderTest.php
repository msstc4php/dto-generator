<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Integration\Infrastructure;

use MSSTC4PHP\DtoGenerator\Application\Port\DocumentLoadFailed;
use MSSTC4PHP\DtoGenerator\Infrastructure\Document\FileDocumentLoader;
use PHPUnit\Framework\TestCase;

final class FileDocumentLoaderTest extends TestCase
{
    private const DIR = __DIR__ . '/../../Fixtures/Documents';

    private const EXPECTED = ['openapi' => '3.1.0', 'components' => ['schemas' => ['User' => ['type' => 'object']]]];

    /**
     * @dataProvider validFiles
     */
    public function testDecodesYamlAndJson(string $file): void
    {
        $document = (new FileDocumentLoader())->load(self::DIR . '/' . $file);

        self::assertSame(self::EXPECTED, $document->root());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validFiles(): array
    {
        return [
            'yaml' => ['valid.yaml'],
            'yml' => ['valid.yml'],
            'json' => ['valid.json'],
        ];
    }

    public function testReportsTheNormalizedRequestedPath(): void
    {
        $document = (new FileDocumentLoader())->load(self::DIR . '/../Documents/./valid.yaml');

        self::assertStringEndsWith('tests/Fixtures/Documents/valid.yaml', $document->path());
        self::assertStringNotContainsString('..', $document->path());
    }

    public function testReusesTheDecodedContentForAnotherSpelling(): void
    {
        $loader = new FileDocumentLoader();
        $first = $loader->load(self::DIR . '/valid.yaml');
        $second = $loader->load(self::DIR . '/../Documents/valid.yaml');

        self::assertSame($first->root(), $second->root());
        self::assertSame($first->path(), $second->path());
    }

    /**
     * @dataProvider failures
     */
    public function testReportsUnusableFiles(string $file, string $message): void
    {
        $this->expectException(DocumentLoadFailed::class);
        $this->expectExceptionMessage($message);

        (new FileDocumentLoader())->load(self::DIR . '/' . $file);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function failures(): array
    {
        return [
            'missing' => ['missing.yaml', 'does not exist'],
            'broken yaml' => ['broken.yaml', 'is not valid'],
            'broken json' => ['broken.json', 'is not valid'],
            'scalar' => ['scalar.json', 'must contain an object at the top level'],
            'list' => ['list.yaml', 'must contain an object at the top level'],
            'unsupported' => ['notes.txt', 'must be YAML (.yaml, .yml) or JSON (.json)'],
            'directory' => ['.', 'does not exist'],
        ];
    }

    public function testPinsYamlScalarGotchas(): void
    {
        $root = (new FileDocumentLoader())->load(self::DIR . '/gotchas.yaml')->root();

        // symfony/yaml turns unquoted dates into Unix timestamps; see known-issues.md.
        self::assertSame(1577836800, $root['created']);
        self::assertSame('2020-01-01', $root['quoted']);
        // Object tags are never instantiated.
        self::assertNull($root['object']);
    }

    public function testCachesTheDecodedContent(): void
    {
        $file = $this->temporaryFile('cached.json', '{"version": 1}');
        $loader = new FileDocumentLoader();
        $first = $loader->load($file);
        file_put_contents($file, '{"version": 2}');

        self::assertSame($first->root(), $loader->load($file)->root());
        unlink($file);
    }

    public function testReportsAnUnreadableFile(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('root can read any file');
        }

        $file = $this->temporaryFile('locked.json', '{}');
        chmod($file, 0000);

        try {
            $this->expectException(DocumentLoadFailed::class);
            $this->expectExceptionMessage('cannot be read');
            (new FileDocumentLoader())->load($file);
        } finally {
            chmod($file, 0644);
            unlink($file);
        }
    }

    private function temporaryFile(string $name, string $content): string
    {
        $file = sys_get_temp_dir() . '/dto-generator-' . bin2hex(random_bytes(4)) . '-' . $name;
        file_put_contents($file, $content);

        return $file;
    }
}
