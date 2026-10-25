<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Infrastructure\Document;

use __PHP_Incomplete_Class;
use Closure;
use Composer\InstalledVersions;
use MSSTC4PHP\DtoGenerator\Infrastructure\Document\DocumentDecoder;
use MSSTC4PHP\DtoGenerator\Infrastructure\Document\YamlCache;
use PHPUnit\Framework\TestCase;

final class YamlCacheTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/dto-generator-yaml-cache-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        $this->remove($this->directory);
    }

    public function testGivesBackWhatItKeepsForTheSameTextAndVersions(): void
    {
        $cache = new YamlCache($this->directory, 'v1');
        $content = $this->large();
        $cache->put($content, ['a' => [1, 'x', null, 1.5, true]]);

        self::assertSame(['a' => [1, 'x', null, 1.5, true]], $cache->get($content));
        self::assertNull($cache->get($content . ' '));
        self::assertNull((new YamlCache($this->directory, 'v2'))->get($content));
        self::assertSame([$this->directory . '/' . hash('sha256', $content) . '.ser'], $this->entries());
    }

    public function testKeepsNoSmallText(): void
    {
        $cache = new YamlCache($this->directory, 'v1');
        $content = str_repeat('a', 16383);
        $cache->put($content, ['a' => 1]);

        self::assertNull($cache->get($content));
        self::assertSame([], $this->entries());

        $content .= 'a';
        $cache->put($content, ['a' => 1]);
        self::assertSame(['a' => 1], $cache->get($content));
    }

    /**
     * @dataProvider unreadable
     */
    public function testMissesAnEntryThatDoesNotReadBack(string $serialized): void
    {
        $cache = new YamlCache($this->directory, 'v1');
        $content = $this->large();
        file_put_contents($this->directory . '/' . hash('sha256', $content) . '.ser', $serialized);

        self::assertNull($cache->get($content));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unreadable(): iterable
    {
        yield 'a write cut short' => [substr(serialize(['v1', ['a' => 1]]), 0, -2)];
        yield 'no array' => [serialize('v1')];
        yield 'no version' => [serialize([1 => ['a' => 1]])];
        yield 'no document' => [serialize(['v1'])];
        yield 'a document that is no array' => [serialize(['v1', 'a'])];
        yield 'an object' => ['O:8:"stdClass":0:{}'];
    }

    public function testReadsNoObject(): void
    {
        $cache = new YamlCache($this->directory, 'v1');
        $content = $this->large();
        file_put_contents($this->directory . '/' . hash('sha256', $content) . '.ser', 'a:2:{i:0;s:2:"v1";i:1;a:1:{i:0;O:8:"stdClass":0:{}}}');

        $read = $cache->get($content);
        self::assertIsArray($read);
        self::assertInstanceOf(__PHP_Incomplete_Class::class, $read[0]);
    }

    public function testDatesWhatItWritesAndReadsAndDropsWhatWentUnreadForThirtyDays(): void
    {
        $now = 2000000000;
        $cache = new YamlCache($this->directory, 'v1', static function () use (&$now): int {
            return $now;
        });
        $cache->put($this->large('kept'), ['kept']);
        $cache->put($this->large('read'), ['read']);
        $cache->put($this->large('dropped'), ['dropped']);
        self::assertSame($now, filemtime($this->path('kept')));

        $now += 2592000;
        clearstatcache();
        self::assertSame(['read'], $cache->get($this->large('read')));
        self::assertSame($now, filemtime($this->path('read')));
        touch($this->path('dropped'), $now - 2592001);
        $cache->put($this->large('new'), ['new']);

        self::assertFileExists($this->path('kept'));
        self::assertFileExists($this->path('read'));
        self::assertFileDoesNotExist($this->path('dropped'));
        self::assertFileExists($this->path('new'));
    }

    public function testWritesNothingWhereItCannot(): void
    {
        $cache = new YamlCache($this->directory . '/missing', 'v1');
        $cache->put($this->large(), ['a' => 1]);

        self::assertNull($cache->get($this->large()));
        self::assertSame([], $this->entries());
    }

    public function testTheDecoderReadsTheCacheFirstAndKeepsWhatItDecodes(): void
    {
        $cache = new YamlCache($this->directory, 'v1');
        $yaml = "a: 1\n#" . str_repeat('x', 16384) . "\n";
        $decoder = new DocumentDecoder($cache);

        self::assertSame(['a' => 1], $decoder->decode($yaml, false));
        self::assertSame(['a' => 1], $cache->get($yaml));

        $cache->put($yaml, ['a' => 'cached', 'b' => 2]);
        self::assertSame(['a' => 'cached', 'b' => 2], $decoder->decode($yaml, false));

        $json = '{"a": 1, "x": "' . str_repeat('x', 16384) . '"}';
        $cache->put($json, ['a' => 'cached']);
        self::assertSame(1, $decoder->decode($json, true)['a']);
        self::assertCount(2, $this->entries());
    }

    public function testVersionIsTheCommitsOfTheGeneratorAndOfSymfonyYaml(): void
    {
        $expected = [];
        foreach (['msstc4php/dto-generator', 'symfony/yaml'] as $package) {
            $expected[] = $package . '@' . (InstalledVersions::getReference($package) ?? InstalledVersions::getPrettyVersion($package));
        }

        self::assertSame(implode(' ', $expected), YamlCache::version());
    }

    public function testIsTheUsersCacheDirectory(): void
    {
        $cache = YamlCache::forUser(fn (string $name): ?string => ['XDG_CACHE_HOME' => $this->directory . '/xdg', 'HOME' => $this->directory . '/home'][$name] ?? null);
        self::assertInstanceOf(YamlCache::class, $cache);
        self::assertSame(0700, fileperms($this->directory . '/xdg/dto-generator/yaml') & 0777);
        $cache->put($this->large(), ['a' => 1]);
        self::assertSame(['a' => 1], (new YamlCache($this->directory . '/xdg/dto-generator/yaml', YamlCache::version()))->get($this->large()));

        self::assertInstanceOf(YamlCache::class, YamlCache::forUser(fn (string $name): ?string => ['XDG_CACHE_HOME' => '', 'HOME' => $this->directory . '/home'][$name] ?? null));
        self::assertDirectoryExists($this->directory . '/home/.cache/dto-generator/yaml');

        self::assertInstanceOf(YamlCache::class, YamlCache::forUser(fn (string $name): ?string => ['HOME' => '', 'LOCALAPPDATA' => $this->directory . '/local'][$name] ?? null));
        self::assertDirectoryExists($this->directory . '/local/dto-generator/yaml');

        self::assertInstanceOf(YamlCache::class, YamlCache::forUser(fn (string $name): ?string => ['DTO_GENERATOR_YAML_CACHE' => '1', 'XDG_CACHE_HOME' => $this->directory . '/xdg'][$name] ?? null));
    }

    public function testIsNoneWhenSwitchedOffOrWithoutADirectory(): void
    {
        self::assertNull(YamlCache::forUser(fn (string $name): ?string => ['DTO_GENERATOR_YAML_CACHE' => '0', 'XDG_CACHE_HOME' => $this->directory][$name] ?? null));
        self::assertNull(YamlCache::forUser(static fn (string $name): ?string => null));
        self::assertNull(YamlCache::forUser(static fn (string $name): ?string => ['XDG_CACHE_HOME' => '', 'HOME' => '', 'LOCALAPPDATA' => ''][$name] ?? null));
        self::assertDirectoryDoesNotExist($this->directory . '/dto-generator');
    }

    /**
     * @dataProvider permissions
     */
    public function testIsNoneWithoutADirectoryOnlyTheUserMayWrite(int $permissions, bool $private): void
    {
        $directory = $this->directory . '/base/dto-generator/yaml';
        mkdir($directory, 0700, true);
        chmod($directory, $permissions);

        $cache = YamlCache::forUser(fn (string $name): ?string => ['XDG_CACHE_HOME' => $this->directory . '/base'][$name] ?? null);
        chmod($directory, 0700);

        self::assertSame($private, $cache instanceof YamlCache);
    }

    /**
     * @return iterable<string, array{int, bool}>
     */
    public static function permissions(): iterable
    {
        yield 'the user alone' => [0700, true];
        yield 'others may enter' => [0711, true];
        yield 'others may read' => [0755, true];
        yield 'the group may write' => [0720, false];
        yield 'others may write' => [0702, false];
        yield 'not even the user may write' => [0500, false];
    }

    public function testIsNoneWhereAFileStandsForTheDirectory(): void
    {
        mkdir($this->directory . '/base/dto-generator', 0700, true);
        file_put_contents($this->directory . '/base/dto-generator/yaml', '');

        self::assertNull(YamlCache::forUser(fn (string $name): ?string => ['XDG_CACHE_HOME' => $this->directory . '/base'][$name] ?? null));
    }

    public function testLeavesTheErrorHandlerAsItFoundIt(): void
    {
        $cache = new YamlCache($this->directory, 'v1');
        $this->assertKeepsTheErrorHandler(fn (): ?array => $cache->get($this->large()));
        $this->assertKeepsTheErrorHandler(fn () => $cache->put($this->large(), ['a' => 1]));
        $this->assertKeepsTheErrorHandler(fn (): ?YamlCache => YamlCache::forUser(fn (string $name): ?string => ['XDG_CACHE_HOME' => $this->directory][$name] ?? null));
    }

    private function assertKeepsTheErrorHandler(Closure $call): void
    {
        $handler = static fn (): bool => false;
        set_error_handler($handler);
        try {
            $call();
            self::assertSame($handler, set_error_handler(null));
            restore_error_handler();
        } finally {
            restore_error_handler();
        }
    }

    private function large(string $text = 'x'): string
    {
        return $text . ': ' . str_repeat('y', 16384) . "\n";
    }

    private function path(string $text): string
    {
        return $this->directory . '/' . hash('sha256', $this->large($text)) . '.ser';
    }

    /**
     * @return list<string>
     */
    private function entries(): array
    {
        $entries = glob($this->directory . '/*');

        return is_array($entries) ? $entries : [];
    }

    private function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            chmod($path, 0700);
            foreach (array_diff((array) scandir($path), ['.', '..']) as $entry) {
                $this->remove($path . '/' . $entry);
            }

            rmdir($path);
        } elseif (file_exists($path) || is_link($path)) {
            unlink($path);
        }
    }
}
