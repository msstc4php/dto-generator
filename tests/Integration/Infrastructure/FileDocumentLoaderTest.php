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
            'json with BOM' => ['bom.json'],
            'yaml with BOM' => ['bom.yaml'],
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
            'nul byte' => ["valid\0.yaml", 'does not exist'],
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

    /** @var list<string> */
    private array $temporary = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->temporary) as $path) {
            if (is_link($path) || is_file($path)) {
                chmod($path, 0644);
                unlink($path);
            } elseif (is_dir($path)) {
                rmdir($path);
            }
        }
    }

    public function testCachesTheDecodedContentPerRealFile(): void
    {
        $directory = $this->temporaryDirectory();
        $file = $directory . '/cached.json';
        $link = $directory . '/alias.json';
        file_put_contents($file, '{"version": 1}');
        symlink($file, $link);
        $this->temporary[] = $file;
        $this->temporary[] = $link;
        $loader = new FileDocumentLoader();

        $first = $loader->load($file);
        file_put_contents($file, '{"version": 2}');
        $viaLink = $loader->load($link);

        self::assertSame(['version' => 1], $viaLink->root());
        self::assertSame($link, $viaLink->path(), 'the requested spelling stays the identity');
        self::assertSame($first->root(), $loader->load($file)->root());
    }

    public function testReportsAnUnreadableFile(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('root can read any file');
        }

        $file = $this->temporaryDirectory() . '/locked.json';
        file_put_contents($file, '{}');
        chmod($file, 0000);
        $this->temporary[] = $file;

        $this->expectException(DocumentLoadFailed::class);
        $this->expectExceptionMessage('cannot be read');

        (new FileDocumentLoader())->load($file);
    }

    public function testFailuresCarryThePath(): void
    {
        try {
            (new FileDocumentLoader())->load(self::DIR . '/broken.json');
            self::fail('expected a load failure');
        } catch (DocumentLoadFailed $exception) {
            self::assertStringEndsWith('tests/Fixtures/Documents/broken.json', $exception->path());
        }
    }

    private function temporaryDirectory(): string
    {
        $directory = sys_get_temp_dir() . '/dto-generator-' . bin2hex(random_bytes(4));
        mkdir($directory);
        $this->temporary[] = $directory;

        return $directory;
    }
}
