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

    public function testRejectsYamlAliasesThatExpandBeyondTheLimit(): void
    {
        // Each level aliases the previous one six times: 6^8 values from under a kilobyte.
        $yaml = "openapi: 3.1.0\nl0: &l0 [1, 2, 3, 4, 5, 6]\n";
        for ($level = 1; $level <= 8; $level++) {
            $yaml .= sprintf("l%d: &l%1\$d [%s]\n", $level, implode(', ', array_fill(0, 6, '*l' . ($level - 1))));
        }

        $this->expectException(DocumentLoadFailed::class);
        $this->expectExceptionMessage(sprintf('its YAML aliases expand to more than %d values', 10 * strlen($yaml)));

        (new FileDocumentLoader())->load($this->file('bomb.yaml', $yaml));
    }

    public function testAcceptsAliasesThatExpandUpToTheLimit(): void
    {
        [$yaml, $values] = $this->aliasedTo(10);

        self::assertSame($values, self::values((new FileDocumentLoader())->load($this->file('aliases.yaml', $yaml))->root()));
    }

    public function testRejectsAliasesOneByteShortOfTheLimit(): void
    {
        [$yaml] = $this->aliasedTo(10);

        $this->expectException(DocumentLoadFailed::class);
        $this->expectExceptionMessage('its YAML aliases expand to more than');

        (new FileDocumentLoader())->load($this->file('aliases.yaml', substr($yaml, 0, -2) . "\n"));
    }

    /**
     * A YAML document whose values number exactly $ratio times its length.
     *
     * @return array{string, int} the document and its number of values
     */
    private function aliasedTo(int $ratio): array
    {
        $base = implode(', ', range(1, 100));
        for ($copies = 1;; $copies++) {
            $yaml = "base: &b [{$base}]\ncopies: [" . implode(', ', array_fill(0, $copies, '*b')) . "]\n#";
            $values = 2 + 100 + 101 * $copies;
            $length = intdiv($values, $ratio);
            if ($values % $ratio === 0 && $length > strlen($yaml)) {
                return [$yaml . str_repeat('x', $length - strlen($yaml) - 1) . "\n", $values];
            }
        }
    }

    /**
     * @param array<array-key, mixed> $value
     */
    private static function values(array $value): int
    {
        $count = 0;
        foreach ($value as $item) {
            $count += 1 + (is_array($item) ? self::values($item) : 0);
        }

        return $count;
    }

    private function file(string $name, string $content): string
    {
        $file = $this->temporaryDirectory() . '/' . $name;
        file_put_contents($file, $content);
        $this->temporary[] = $file;

        return $file;
    }

    private function temporaryDirectory(): string
    {
        $directory = sys_get_temp_dir() . '/dto-generator-' . bin2hex(random_bytes(4));
        mkdir($directory);
        $this->temporary[] = $directory;

        return $directory;
    }
}
