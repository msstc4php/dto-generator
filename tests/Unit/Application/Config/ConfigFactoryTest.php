<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Application\Config;

use InvalidArgumentException;
use MSSTC4PHP\DtoGenerator\Application\Config\ConfigFactory;
use MSSTC4PHP\DtoGenerator\Application\Config\GeneratorConfig;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostic;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Target\PhpVersion;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class ConfigFactoryTest extends TestCase
{
    private const PATH = '/project/config/dto-generator.yaml';

    private const MINIMAL = [
        'version' => 1,
        'sources' => [['spec' => '../api/openapi.yaml', 'namespace' => 'App\Dto', 'outputDir' => '../src/Dto']],
    ];

    public function testAppliesDefaultsToAMinimalConfig(): void
    {
        $config = $this->valid(self::MINIMAL);

        self::assertSame(self::PATH, $config->path());
        self::assertSame('/project/config', $config->baseDir());
        self::assertNull($config->target()->php());
        self::assertNull($config->target()->metadata());
        self::assertTrue($config->target()->isStrict());
        self::assertSame('immutable', $config->dto()->mutability()->value());
        self::assertSame('auto', $config->dto()->accessors()->value());
        self::assertSame('DateTimeImmutable', $config->dto()->dateTimeClass()->value());
        self::assertSame('extends', $config->dto()->allOfStrategy()->value());
        self::assertSame([], $config->formats());
        self::assertSame([], $config->extensions()->classes());
        self::assertTrue($config->extensions()->discover());
        self::assertNull($config->extensions()->verifyClasses());

        $source = $config->sources()[0];
        self::assertSame('/project/api/openapi.yaml', $source->spec());
        self::assertSame('App\Dto', $source->namespace());
        self::assertSame('/project/src/Dto', $source->outputDir());
        self::assertSame(['*'], $source->include());
        self::assertSame([], $source->exclude());
    }

    public function testReadsAFullConfig(): void
    {
        $config = $this->valid([
            'version' => 1,
            'target' => ['php' => '8.2', 'metadata' => 'annotations', 'strict' => false],
            'dto' => ['mutability' => 'mutable', 'accessors' => 'getters', 'dateTimeClass' => 'DateTime', 'allOfStrategy' => 'merge'],
            'formats' => ['uuid' => ['type' => '\Symfony\Component\Uid\Uuid']],
            'attributeAliases' => ['x-audit' => ['class' => 'App\Attr\Audited']],
            'verifyClasses' => false,
            'discoverExtensions' => false,
            'extensions' => ['MSSTC4PHP\DtoGeneratorBridgeSymfony\SymfonyExtension'],
            'extensionConfig' => ['symfony' => ['version' => 'auto']],
            'sources' => [[
                'spec' => '/abs/openapi.yaml',
                'namespace' => '\App\Dto\Public',
                'outputDir' => 'src/Dto',
                'include' => ['User*'],
                'exclude' => ['UserInternal'],
            ]],
        ]);

        self::assertNotNull($config->target()->php());
        self::assertSame('8.2', $config->target()->php()->toString());
        self::assertNotNull($config->target()->metadata());
        self::assertSame('annotations', $config->target()->metadata()->value());
        self::assertFalse($config->target()->isStrict());
        self::assertSame('mutable', $config->dto()->mutability()->value());
        self::assertSame('merge', $config->dto()->allOfStrategy()->value());
        self::assertSame('Symfony\Component\Uid\Uuid', $config->formats()['uuid']->fqcn());
        self::assertSame(['x-audit' => ['class' => 'App\Attr\Audited']], $config->extensions()->aliases());
        self::assertFalse($config->extensions()->verifyClasses());
        self::assertFalse($config->extensions()->discover());
        self::assertSame(
            ['MSSTC4PHP\DtoGeneratorBridgeSymfony\SymfonyExtension'],
            array_map(static fn (ClassName $class): string => $class->fqcn(), $config->extensions()->classes()),
        );
        self::assertSame(['symfony' => ['version' => 'auto']], $config->extensions()->config());
        self::assertSame('/abs/openapi.yaml', $config->sources()[0]->spec());
        self::assertSame('App\Dto\Public', $config->sources()[0]->namespace());
        self::assertSame(['User*'], $config->sources()[0]->include());
        self::assertSame(['UserInternal'], $config->sources()[0]->exclude());
    }

    /**
     * @dataProvider unquotedVersions
     */
    public function testAcceptsUnquotedYamlVersions(float $php, string $expected): void
    {
        $config = $this->valid(['target' => ['php' => $php]] + self::MINIMAL);

        self::assertNotNull($config->target()->php());
        self::assertSame($expected, $config->target()->php()->toString());
    }

    /**
     * @return array<string, array{float|string, string}>
     */
    public static function unquotedVersions(): array
    {
        return [
            'float 8.2' => [8.2, '8.2'],
            'float 8.0' => [8.0, '8.0'],
            'float 7.4' => [7.4, '7.4'],
        ];
    }

    /**
     * @dataProvider invalidConfigs
     *
     * @param array<array-key, mixed> $raw
     */
    public function testReportsProblemsWithTheirLocation(array $raw, string $message, string $pointer): void
    {
        $diagnostics = new Diagnostics();

        self::assertNull((new ConfigFactory())->create($raw, self::PATH, $diagnostics));
        $matching = array_values(array_filter(
            $diagnostics->errors(),
            static fn (Diagnostic $error): bool => strpos($error->message(), $message) !== false,
        ));
        self::assertCount(1, $matching, implode("\n", array_map(static fn (Diagnostic $d): string => $d->toString(), $diagnostics->all())));
        self::assertSame(self::PATH . '#' . $pointer, $matching[0]->location()->toString());
    }

    /**
     * @return array<string, array{array<array-key, mixed>, string, string}>
     */
    public static function invalidConfigs(): array
    {
        $source = ['spec' => 'a.yaml', 'namespace' => 'App\Dto', 'outputDir' => 'src'];

        return [
            'missing version' => [['sources' => [$source]], '"version" is required', ''],
            'wrong version' => [['version' => 2, 'sources' => [$source]], '"version" must be 1', '/version'],
            'unknown root key' => [['version' => 1, 'sorces' => [], 'sources' => [$source]], 'Unknown key "sorces"', '/sorces'],
            'unknown target key' => [['version' => 1, 'target' => ['phpp' => '8.2'], 'sources' => [$source]], 'Unknown key "phpp"', '/target/phpp'],
            'unsupported php' => [['version' => 1, 'target' => ['php' => '9.9'], 'sources' => [$source]], 'PHP 9.9 is not supported', '/target/php'],
            'php as list' => [['version' => 1, 'target' => ['php' => ['8.2']], 'sources' => [$source]], 'must be "auto" or a version', '/target/php'],
            'bad metadata' => [['version' => 1, 'target' => ['metadata' => 'attribute'], 'sources' => [$source]], 'must be one of: auto, attributes, annotations, none', '/target/metadata'],
            'strict not bool' => [['version' => 1, 'target' => ['strict' => 'yes'], 'sources' => [$source]], '"strict" must be true or false', '/target/strict'],
            'target list' => [['version' => 1, 'target' => ['8.2'], 'sources' => [$source]], '"target" must be an object', '/target'],
            'bad mutability' => [['version' => 1, 'dto' => ['mutability' => 'frozen'], 'sources' => [$source]], 'must be one of: immutable, mutable', '/dto/mutability'],
            'format without type' => [['version' => 1, 'formats' => ['uuid' => []], 'sources' => [$source]], '"type" is required', '/formats/uuid'],
            'format bad class' => [['version' => 1, 'formats' => ['uuid' => ['type' => 'Not A Class']], 'sources' => [$source]], 'is not a valid class name', '/formats/uuid/type'],
            'reserved alias prefix' => [['version' => 1, 'attributeAliases' => ['x-php-audit' => []], 'sources' => [$source]], 'outside the reserved', '/attributeAliases/x-php-audit'],
            'alias not object' => [['version' => 1, 'attributeAliases' => ['x-audit' => 'App\A'], 'sources' => [$source]], 'An alias must be an object', '/attributeAliases/x-audit'],
            'bad verifyClasses' => [['version' => 1, 'verifyClasses' => 'yes', 'sources' => [$source]], 'must be "auto", true or false', '/verifyClasses'],
            'bad extension class' => [['version' => 1, 'extensions' => ['Not A Class'], 'sources' => [$source]], 'is not a valid class name', '/extensions/0'],
            'missing sources' => [['version' => 1], '"sources" is required', ''],
            'empty sources' => [['version' => 1, 'sources' => []], '"sources" must be a non-empty list', '/sources'],
            'source not object' => [['version' => 1, 'sources' => ['a.yaml']], 'Expected an object', '/sources/0'],
            'source missing spec' => [['version' => 1, 'sources' => [['namespace' => 'App', 'outputDir' => 'src']]], '"spec" is required', '/sources/0'],
            'unknown source key' => [['version' => 1, 'sources' => [$source + ['output' => 'x']]], 'Unknown key "output"', '/sources/0/output'],
            'bad namespace' => [['version' => 1, 'sources' => [['namespace' => 'App\1Dto'] + $source]], 'is not a valid namespace', '/sources/0/namespace'],
            'empty include' => [['version' => 1, 'sources' => [$source + ['include' => []]]], '"include" must not be empty', '/sources/0/include'],
            'include not strings' => [['version' => 1, 'sources' => [$source + ['include' => [1]]]], 'Expected a non-empty string', '/sources/0/include/0'],
            'unknown dto key' => [['version' => 1, 'dto' => ['mutable' => true], 'sources' => [$source]], 'Unknown key "mutable"', '/dto/mutable'],
            'unknown format key' => [['version' => 1, 'formats' => ['uuid' => ['type' => 'App\Uuid', 'kind' => 1]], 'sources' => [$source]], 'Unknown key "kind"', '/formats/uuid/kind'],
            'reserved x-dto alias' => [['version' => 1, 'attributeAliases' => ['x-dto-audit' => []], 'sources' => [$source]], 'outside the reserved', '/attributeAliases/x-dto-audit'],
            'alias of a core key' => [['version' => 1, 'attributeAliases' => ['x-enum-descriptions' => ['class' => 'App\A']], 'sources' => [$source]], 'outside the reserved', '/attributeAliases/x-enum-descriptions'],
            'alias of x-enum-varnames' => [['version' => 1, 'attributeAliases' => ['x-enum-varnames' => ['class' => 'App\A']], 'sources' => [$source]], 'outside the reserved', '/attributeAliases/x-enum-varnames'],
            'alias without class' => [['version' => 1, 'attributeAliases' => ['x-audit' => ['args' => []]], 'sources' => [$source]], 'An alias must be an object', '/attributeAliases/x-audit'],
            'alias with another key' => [['version' => 1, 'attributeAliases' => ['x-audit' => ['class' => 'App\A', 'with' => 1]], 'sources' => [$source]], 'An alias must be an object', '/attributeAliases/x-audit'],
            'alias with a class that is no string' => [['version' => 1, 'attributeAliases' => ['x-audit' => ['class' => 5]], 'sources' => [$source]], 'An alias must be an object', '/attributeAliases/x-audit'],
            'empty alias' => [['version' => 1, 'attributeAliases' => ['x-audit' => []], 'sources' => [$source]], 'An alias must be an object', '/attributeAliases/x-audit'],
            'alias list' => [['version' => 1, 'attributeAliases' => ['x-audit' => ['App\A']], 'sources' => [$source]], 'An alias must be an object', '/attributeAliases/x-audit'],
            'empty spec' => [['version' => 1, 'sources' => [['spec' => ''] + $source]], '"spec" must be a non-empty string', '/sources/0/spec'],
            'numeric spec' => [['version' => 1, 'sources' => [['spec' => 5] + $source]], '"spec" must be a non-empty string', '/sources/0/spec'],
            'include string' => [['version' => 1, 'sources' => [$source + ['include' => 'User*']]], '"include" must be a list of strings', '/sources/0/include'],
            'source as list' => [['version' => 1, 'sources' => [['a.yaml']]], 'Expected an object', '/sources/0'],
            'empty source object' => [['version' => 1, 'sources' => [[]]], '"spec" is required', '/sources/0'],
        ];
    }

    /**
     * @dataProvider malformedSources
     *
     * @param array<array-key, mixed> $sources
     */
    public function testReportsOnlyTheShapeOfMalformedSources(array $sources): void
    {
        $diagnostics = new Diagnostics();
        $config = (new ConfigFactory())->create(['version' => 1, 'sources' => $sources], self::PATH, $diagnostics);

        self::assertNull($config);
        self::assertSame(
            ['error ' . self::PATH . '#/sources: "sources" must be a non-empty list.'],
            array_map(static fn (Diagnostic $d): string => $d->toString(), $diagnostics->all()),
        );
    }

    /**
     * @return array<string, array{array<array-key, mixed>}>
     */
    public static function malformedSources(): array
    {
        return [
            'empty' => [[]],
            'map' => [['main' => 'a.yaml']],
        ];
    }

    /**
     * @param array<array-key, mixed> $raw
     */
    private function valid(array $raw): GeneratorConfig
    {
        $diagnostics = new Diagnostics();
        $config = (new ConfigFactory())->create($raw, self::PATH, $diagnostics);

        self::assertSame([], array_map(static fn (Diagnostic $d): string => $d->toString(), $diagnostics->all()));
        self::assertNotNull($config);

        return $config;
    }

    /**
     * @dataProvider repeatedProblems
     *
     * @param array<array-key, mixed> $raw
     */
    public function testReportsEveryOccurrenceOfAProblem(array $raw, string $message, int $count): void
    {
        $diagnostics = new Diagnostics();
        (new ConfigFactory())->create($raw, self::PATH, $diagnostics);

        self::assertCount($count, array_filter(
            $diagnostics->errors(),
            static fn (Diagnostic $error): bool => strpos($error->message(), $message) !== false,
        ));
    }

    /**
     * @return array<string, array{array<array-key, mixed>, string, int}>
     */
    public static function repeatedProblems(): array
    {
        $source = ['spec' => 'a.yaml', 'namespace' => 'App\Dto', 'outputDir' => 'src'];

        return [
            'formats without type' => [['version' => 1, 'formats' => ['a' => [], 'b' => []], 'sources' => [$source]], '"type" is required', 2],
            'reserved aliases' => [['version' => 1, 'attributeAliases' => ['x-php-a' => [], 'x-php-b' => []], 'sources' => [$source]], 'outside the reserved', 2],
            'non-object aliases' => [['version' => 1, 'attributeAliases' => ['x-a' => 1, 'x-b' => 2], 'sources' => [$source]], 'An alias must be an object', 2],
            'sources without spec' => [['version' => 1, 'sources' => [['namespace' => 'A', 'outputDir' => 'o'], ['namespace' => 'B', 'outputDir' => 'o']]], '"spec" is required', 2],
            'non-string includes' => [['version' => 1, 'sources' => [$source + ['include' => [1, 2]]]], 'Expected a non-empty string', 2],
            'non-object sources' => [['version' => 1, 'sources' => ['a', 'b']], 'Expected an object', 2],
        ];
    }

    public function testKeepsEveryEntryOfMultiValuedSettings(): void
    {
        $config = $this->valid([
            'version' => 1,
            'formats' => ['uuid' => ['type' => 'App\Uuid'], '200' => ['type' => 'App\Ok']],
            'attributeAliases' => ['x-phpstorm' => ['class' => 'App\A'], 'x-dtox' => ['class' => 'App\B'], 'x-empty' => ['class' => 'App\C', 'args' => []]],
            'sources' => [
                ['spec' => 'a.yaml', 'namespace' => 'App\A', 'outputDir' => 'a', 'include' => ['A*', 'B*']],
                ['spec' => 'b.yaml', 'namespace' => 'App\B', 'outputDir' => 'b'],
            ],
        ]);

        self::assertSame(['uuid', '200'], array_map('strval', array_keys($config->formats())));
        self::assertSame(['x-phpstorm', 'x-dtox', 'x-empty'], array_keys($config->extensions()->aliases()));
        self::assertCount(2, $config->sources());
        self::assertSame(['A*', 'B*'], $config->sources()[0]->include());
    }

    public function testRequiresAnAbsoluteConfigPath(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Config path "dto-generator.yaml" must be absolute');

        (new ConfigFactory())->create(self::MINIMAL, 'dto-generator.yaml', new Diagnostics());
    }

    /**
     * @dataProvider yamlVersions
     */
    public function testReadsPhpVersionsAsYamlDecodesThem(string $yaml, ?string $expected, ?string $error): void
    {
        $raw = Yaml::parse($yaml . "\nversion: 1\nsources: [{spec: a.yaml, namespace: App, outputDir: src}]");
        self::assertIsArray($raw);
        $diagnostics = new Diagnostics();
        $config = (new ConfigFactory())->create($raw, self::PATH, $diagnostics);

        if ($error !== null) {
            self::assertNull($config);
            self::assertStringContainsString($error, $diagnostics->errors()[0]->message());

            return;
        }

        self::assertNotNull($config);
        self::assertSame($expected, $config->target()->php() instanceof PhpVersion ? $config->target()->php()->toString() : null);
    }

    /**
     * @return array<string, array{string, string|null, string|null}>
     */
    public static function yamlVersions(): array
    {
        return [
            'float' => ['target: {php: 8.2}', '8.2', null],
            'float with zero minor' => ['target: {php: 8.0}', '8.0', null],
            'quoted' => ["target: {php: '8.1'}", '8.1', null],
            'explicit auto' => ['target: {php: auto}', null, null],
            'two-digit minor is not rounded' => ['target: {php: 8.05}', null, '"8.05" is not a PHP version'],
            'integer' => ['target: {php: 8}', null, '"8" is not a PHP version'],
            'unsupported' => ['target: {php: 9.0}', null, 'PHP 9.0 is not supported'],
        ];
    }

    public function testComplainsAboutAnEmptyIncludeOnlyWhenItIsLiterallyEmpty(): void
    {
        $diagnostics = new Diagnostics();
        (new ConfigFactory())->create(['version' => 1, 'sources' => [['spec' => 'a', 'namespace' => 'A', 'outputDir' => 'o', 'include' => [1]]]], self::PATH, $diagnostics);

        self::assertSame(['Expected a non-empty string.'], array_map(static fn (Diagnostic $d): string => $d->message(), $diagnostics->errors()));
    }
}
