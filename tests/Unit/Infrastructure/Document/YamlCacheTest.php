<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Infrastructure\Document;

use __PHP_Incomplete_Class;
use Closure;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;
use MSSTC4PHP\DtoGenerator\Infrastructure\Document\DocumentDecoder;
use MSSTC4PHP\DtoGenerator\Infrastructure\Document\ExplicitYamlKeys;
use MSSTC4PHP\DtoGenerator\Infrastructure\Document\RewrittenYaml;
use MSSTC4PHP\DtoGenerator\Infrastructure\Document\UndecodableDocument;
use MSSTC4PHP\DtoGenerator\Infrastructure\Document\YamlCache;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\Yaml\Yaml;

final class YamlCacheTest extends TestCase
{
    private string $base;

    private string $directory;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/dto-generator-yaml-cache-' . bin2hex(random_bytes(6));
        $this->directory = $this->base . '/dto-generator/yaml';
        mkdir($this->base, 0700);
    }

    protected function tearDown(): void
    {
        $this->remove($this->base);
    }

    public function testGivesBackWhatItKeepsForTheSameTextAndVersion(): void
    {
        $cache = $this->cache('v1');
        $other = $this->cache('v2');
        $content = $this->large();
        $cache->put($content, ['a' => [1, 'x', null, 1.5, true]]);

        self::assertSame(['a' => [1, 'x', null, 1.5, true]], $cache->get($content));
        self::assertNull($cache->get($content . ' '));
        self::assertNull($other->get($content));

        $other->put($content, ['b' => 2]);
        self::assertSame(['a' => [1, 'x', null, 1.5, true]], $cache->get($content));
        self::assertSame(['b' => 2], $other->get($content));
        self::assertSame(0700, fileperms($this->directory) & 0777);
        self::assertSame(0700, fileperms(dirname($this->directory)) & 0777);
    }

    public function testKeepsNoSmallText(): void
    {
        $cache = $this->cache();
        $content = str_repeat('a', 16383);
        $cache->put($content, ['a' => 1]);

        self::assertNull($cache->get($content));
        self::assertDirectoryDoesNotExist($this->directory);

        $content .= 'a';
        $cache->put($content, ['a' => 1]);
        self::assertSame(['a' => 1], $cache->get($content));
    }

    public function testKeepsNoEntryOfMoreThanFourTimesTheBytesOfItsText(): void
    {
        $cache = $this->cache();
        $content = str_repeat('a', 16384);
        // 16 bytes an entry, besides the text of a string key and value.
        $cache->put($content, ['a' => str_repeat('x', 4 * 16384 - 17 + 1)]);
        self::assertNull($cache->get($content));

        // An integer key and an array value count only their 16 bytes.
        $cache->put($content, [7 => ['b' => str_repeat('x', 4 * 16384 - 16 - 17 + 1)]]);
        self::assertNull($cache->get($content));

        $cache->put($content, [7 => ['b' => str_repeat('x', 4 * 16384 - 16 - 17)]]);
        self::assertSame([7 => ['b' => str_repeat('x', 4 * 16384 - 16 - 17)]], $cache->get($content));
    }

    public function testGivesFloatsBackExactlyWhateverTheSerializePrecision(): void
    {
        $cache = $this->cache();
        $content = $this->large();
        $precision = ini_set('serialize_precision', '14');
        try {
            $cache->put($content, [1.0000000000000002, INF, -0.0]);
            self::assertSame('14', ini_get('serialize_precision'));
        } finally {
            ini_set('serialize_precision', (string) $precision);
        }

        self::assertSame([1.0000000000000002, INF, -0.0], $cache->get($content));
    }

    /**
     * @dataProvider unreadable
     */
    public function testMissesAndDropsAnEntryThatDoesNotReadBack(string $serialized): void
    {
        $cache = $this->cache();
        $content = $this->large();
        $cache->put($content, ['a' => 1]);
        $entries = $this->entries();
        self::assertCount(1, $entries);
        file_put_contents($entries[0], $serialized);

        self::assertNull($cache->get($content));
        self::assertSame([], $this->entries());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unreadable(): iterable
    {
        yield 'a write cut short' => [substr(serialize(['a' => 1]), 0, -2)];
        yield 'no array' => [serialize('a')];
        yield 'an object' => ['O:8:"stdClass":0:{}'];
        yield 'nothing' => [''];
    }

    public function testReadsNoObject(): void
    {
        $cache = $this->cache();
        $content = $this->large();
        $cache->put($content, ['a' => 1]);
        file_put_contents($this->entries()[0], 'a:1:{i:0;O:8:"stdClass":0:{}}');

        $read = $cache->get($content);
        self::assertIsArray($read);
        self::assertInstanceOf(__PHP_Incomplete_Class::class, $read[0]);
    }

    public function testDropsWhatWentUnreadForThirtyDaysAndTheLeastRecentlyReadBeyondItsSize(): void
    {
        $now = 2000000000;
        $clock = static function () use (&$now): int {
            return $now;
        };
        $cache = new YamlCache($this->directory, 'v1', null, $clock);
        $cache->put($this->large('keep'), ['keep']);
        $cache->put($this->large('read'), ['read']);
        $cache->put($this->large('gone'), ['gone']);
        self::assertSame($now, filemtime($this->path('keep')));

        $now += 30 * 86400;
        clearstatcache();
        self::assertSame(['read'], $cache->get($this->large('read')));
        self::assertSame($now, filemtime($this->path('read')));
        touch($this->path('gone'), $now - 30 * 86400 - 1);
        $cache->put($this->large('news'), ['news']);

        self::assertFileExists($this->path('keep'));
        self::assertFileExists($this->path('read'));
        self::assertFileDoesNotExist($this->path('gone'));
        self::assertFileExists($this->path('news'));

        clearstatcache();
        // The entries take the same bytes.
        $size = (int) filesize($this->path('news'));
        $now++;
        $small = new YamlCache($this->directory, 'v1', null, $clock, 2 * $size);
        self::assertSame(['keep'], $small->get($this->large('keep')));
        $small->put($this->large('last'), ['last']);

        self::assertFileExists($this->path('last'));
        self::assertFileExists($this->path('keep'));
        self::assertFileDoesNotExist($this->path('read'));
        self::assertFileDoesNotExist($this->path('news'));
    }

    /**
     * @dataProvider permissions
     */
    public function testUsesADirectoryOnlyTheUserMayUse(int $parent, int $directory, bool $used): void
    {
        if ($directory === 0500 && function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('root may write anywhere.');
        }

        mkdir($this->directory, 0700, true);
        $cache = $this->cache();
        $content = $this->large();
        $cache->put($content, ['a' => 1]);
        chmod(dirname($this->directory), $parent);
        chmod($this->directory, $directory);

        self::assertSame($used ? ['a' => 1] : null, $cache->get($content));
        $cache->put($this->large('other'), ['b' => 2]);
        chmod(dirname($this->directory), 0700);
        chmod($this->directory, 0700);
        self::assertSame($used, is_file($this->path('other')));
    }

    /**
     * @return iterable<string, array{int, int, bool}>
     */
    public static function permissions(): iterable
    {
        yield 'the user alone' => [0700, 0700, true];
        yield 'others may enter' => [0700, 0701, false];
        yield 'the group may read' => [0700, 0740, false];
        yield 'others may write' => [0700, 0702, false];
        yield 'others may enter the parent' => [0701, 0700, false];
        yield 'the group may write to the parent' => [0720, 0700, false];
        yield 'not even the user may write' => [0700, 0500, false];
    }

    public function testUsesNoLinkedDirectory(): void
    {
        mkdir($this->base . '/elsewhere/yaml', 0700, true);
        mkdir(dirname($this->directory), 0700);
        symlink($this->base . '/elsewhere/yaml', $this->directory);
        $this->cache()->put($this->large(), ['a' => 1]);

        self::assertSame([], $this->entries($this->base . '/elsewhere/yaml'));
        unlink($this->directory);

        symlink($this->base . '/elsewhere', $this->base . '/linked');
        (new YamlCache($this->base . '/linked/yaml', 'v1'))->put($this->large(), ['a' => 1]);
        self::assertSame([], $this->entries($this->base . '/elsewhere/yaml'));
    }

    public function testUsesNoDirectoryOfAnotherUser(): void
    {
        $user = function_exists('posix_geteuid') ? posix_geteuid() : 1000;
        $mine = new YamlCache($this->directory, 'v1', $user);
        $mine->put($this->large(), ['a' => 1]);
        self::assertSame(['a' => 1], $mine->get($this->large()));

        self::assertNull((new YamlCache($this->directory, 'v1', $user + 1))->get($this->large()));
    }

    public function testTakesARelativeCacheHomeForNone(): void
    {
        $cache = YamlCache::forUser(fn (string $name): ?string => ['XDG_CACHE_HOME' => 'cache', 'HOME' => $this->base . '/home'][$name] ?? null);
        self::assertInstanceOf(YamlCache::class, $cache);
        $cache->put($this->large(), ['a' => 1]);

        self::assertDirectoryExists($this->base . '/home/.cache/dto-generator/yaml');
    }

    public function testCreatesNoDirectoryUnderOneOfAnotherUser(): void
    {
        $user = function_exists('posix_geteuid') ? posix_geteuid() : 1000;
        (new YamlCache($this->directory, 'v1', $user + 1))->put($this->large(), ['a' => 1]);
        self::assertDirectoryDoesNotExist(dirname($this->directory));

        (new YamlCache($this->directory, 'v1', $user))->put($this->large(), ['a' => 1]);
        self::assertDirectoryExists($this->directory);
    }

    public function testWritesNothingWhereAFileStandsForTheDirectory(): void
    {
        mkdir(dirname($this->directory), 0700);
        file_put_contents($this->directory, '');
        $cache = $this->cache();
        $cache->put($this->large(), ['a' => 1]);

        self::assertNull($cache->get($this->large()));
        self::assertSame('', file_get_contents($this->directory));
    }

    public function testPrunesAfterAWriteThatFailed(): void
    {
        $now = 2000000000;
        $cache = new YamlCache($this->directory, 'v1', null, static function () use (&$now): int {
            return $now;
        });
        $cache->put($this->large('old'), ['a' => 1]);
        mkdir($this->path('blocked'));
        $now += 30 * 86400 + 1;

        $cache->put($this->large('blocked'), ['b' => 2]);

        self::assertNull($cache->get($this->large('blocked')));
        self::assertFileDoesNotExist($this->path('old'));
    }

    public function testTheDecoderReadsTheCacheFirstAndKeepsWhatItDecodes(): void
    {
        $cache = $this->cache();
        $yaml = "a: 1\n? k\n: [1.5, 0x1A, .inf]\n#" . str_repeat('x', 16384) . "\n";
        $decoder = new DocumentDecoder($cache);

        $fresh = $decoder->decode($yaml, false);
        self::assertSame(['a' => 1, 'k' => [1.5, 26, INF]], $fresh);
        self::assertSame($fresh, $cache->get($yaml));
        self::assertSame($fresh, $decoder->decode($yaml, false));

        $cache->put($yaml, ['a' => 'cached', 'b' => 2]);
        self::assertSame(['a' => 'cached', 'b' => 2], $decoder->decode($yaml, false));

        $json = '{"a": 1, "x": "' . str_repeat('x', 16384) . '"}';
        $cache->put($json, ['a' => 'cached']);
        self::assertSame(1, $decoder->decode($json, true)['a']);
    }

    public function testTheDecoderKeepsNothingItCannotDecode(): void
    {
        $cache = $this->cache();
        $yaml = "a: [\n#" . str_repeat('x', 16384) . "\n";

        try {
            (new DocumentDecoder($cache))->decode($yaml, false);
            self::fail('The text decoded.');
        } catch (UndecodableDocument $exception) {
            self::assertDirectoryDoesNotExist($this->directory);
        }
    }

    public function testTheDecoderKeepsNoAliasesExpandedFarBeyondTheirText(): void
    {
        $yaml = 's: &s ' . str_repeat('x', 16384) . "\nl: [" . implode(', ', array_fill(0, 100, '*s')) . "]\n";
        (new DocumentDecoder($this->cache()))->decode($yaml, false);

        self::assertDirectoryDoesNotExist($this->directory);
    }

    public function testTheVersionFollowsTheCodeThatReads(): void
    {
        $one = $this->base . '/one.php';
        $two = $this->base . '/two.php';
        file_put_contents($one, 'one');
        file_put_contents($two, 'two');

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', YamlCache::version([$one]));
        self::assertSame(YamlCache::version([$one]), YamlCache::version([$one]));
        self::assertNotSame(YamlCache::version([$one]), YamlCache::version([$two]));
        self::assertNotSame(YamlCache::version([$one]), YamlCache::version([$one, $two]));
        self::assertNotSame(YamlCache::version([]), hash('sha256', ''));

        $files = YamlCache::readerFiles();
        foreach ([DocumentDecoder::class, ExplicitYamlKeys::class, RewrittenYaml::class, Json::class, YamlCache::class] as $class) {
            self::assertContains((string) (new ReflectionClass($class))->getFileName(), $files);
        }

        $package = dirname((string) (new ReflectionClass(Yaml::class))->getFileName());
        self::assertContains($package . '/Parser.php', $files);
        self::assertContains($package . '/Exception/ParseException.php', $files);
        $yaml = array_values(array_filter($files, static fn (string $file): bool => strpos($file, $package . '/') === 0));
        $sorted = $yaml;
        sort($sorted);
        self::assertSame($sorted, $yaml);
        self::assertCount(5 + count($yaml), $files);
        foreach ($yaml as $file) {
            self::assertStringEndsWith('.php', $file);
        }
    }

    public function testIsTheUsersCacheDirectory(): void
    {
        $cache = YamlCache::forUser(fn (string $name): ?string => ['XDG_CACHE_HOME' => $this->base . '/xdg', 'HOME' => $this->base . '/home'][$name] ?? null);
        self::assertInstanceOf(YamlCache::class, $cache);
        self::assertDirectoryDoesNotExist($this->base . '/xdg');
        $cache->put($this->large(), ['a' => 1]);
        self::assertSame(['a' => 1], (new YamlCache($this->base . '/xdg/dto-generator/yaml', YamlCache::version(YamlCache::readerFiles())))->get($this->large()));

        $home = YamlCache::forUser(fn (string $name): ?string => ['XDG_CACHE_HOME' => '', 'HOME' => $this->base . '/home'][$name] ?? null);
        self::assertInstanceOf(YamlCache::class, $home);
        $home->put($this->large(), ['a' => 1]);
        self::assertDirectoryExists($this->base . '/home/.cache/dto-generator/yaml');

        self::assertInstanceOf(YamlCache::class, YamlCache::forUser(fn (string $name): ?string => ['DTO_GENERATOR_YAML_CACHE' => '1', 'XDG_CACHE_HOME' => $this->base][$name] ?? null));
    }

    public function testIsNoneWhenSwitchedOffOrWithoutADirectory(): void
    {
        self::assertNull(YamlCache::forUser(fn (string $name): ?string => ['XDG_CACHE_HOME' => $this->base][$name] ?? null, 'Windows'));
        self::assertInstanceOf(YamlCache::class, YamlCache::forUser(fn (string $name): ?string => ['XDG_CACHE_HOME' => $this->base][$name] ?? null, 'Linux'));
        self::assertNull(YamlCache::forUser(static fn (string $name): ?string => ['XDG_CACHE_HOME' => 'cache', 'HOME' => 'home'][$name] ?? null));

        self::assertNull(YamlCache::forUser(fn (string $name): ?string => ['DTO_GENERATOR_YAML_CACHE' => '0', 'XDG_CACHE_HOME' => $this->base][$name] ?? null));
        self::assertNull(YamlCache::forUser(static fn (string $name): ?string => null));
        self::assertNull(YamlCache::forUser(static fn (string $name): ?string => ['XDG_CACHE_HOME' => '', 'HOME' => ''][$name] ?? null));
    }

    public function testLeavesTheErrorHandlerAsItFoundIt(): void
    {
        $cache = $this->cache();
        $this->assertKeepsTheErrorHandler(fn () => $cache->put($this->large(), ['a' => 1]));
        $this->assertKeepsTheErrorHandler(fn (): ?array => $cache->get($this->large()));
        $this->assertKeepsTheErrorHandler(fn (): ?array => $cache->get($this->large('missing')));
    }

    private function cache(string $version = 'v1'): YamlCache
    {
        return new YamlCache($this->directory, $version);
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

    private function path(string $text, string $version = 'v1'): string
    {
        return $this->directory . '/' . hash('sha256', $version . $this->large($text)) . '.ser';
    }

    /**
     * @return list<string>
     */
    private function entries(?string $directory = null): array
    {
        $entries = glob(($directory ?? $this->directory) . '/*');

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
