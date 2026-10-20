<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Application\Config;

use InvalidArgumentException;
use MSSTC4PHP\DtoGenerator\Application\Config\ConfigFactory;
use MSSTC4PHP\DtoGenerator\Application\Config\ExcludedExtension;
use MSSTC4PHP\DtoGenerator\Application\Config\GeneratorConfig;
use MSSTC4PHP\DtoGenerator\Application\Config\NameOverride;
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
        self::assertTrue($config->dto()->hasWithers());
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
            'dto' => ['mutability' => 'mutable', 'accessors' => 'getters', 'dateTimeClass' => 'DateTime', 'allOfStrategy' => 'merge', 'withers' => true],
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
        self::assertTrue($config->dto()->hasWithers());
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
            'discoverExtensions not bool' => [['version' => 1, 'discoverExtensions' => 'yes', 'sources' => [$source]], '"discoverExtensions" must be true, false or an object', '/discoverExtensions'],
            'discoverExtensions null' => [['version' => 1, 'discoverExtensions' => null, 'sources' => [$source]], '"discoverExtensions" must be true, false or an object', '/discoverExtensions'],
            'exclude after a bad entry' => [['version' => 1, 'discoverExtensions' => ['exclude' => [1, 'Not A Class']], 'sources' => [$source]], 'class: "Not A Class" is not a valid class name', '/discoverExtensions/exclude/1'],
            'exclude reserved class' => [['version' => 1, 'discoverExtensions' => ['exclude' => ['App\\List']], 'sources' => [$source]], '"List" is a reserved word', '/discoverExtensions/exclude/0'],
            'remoteRefs prefix after a bad entry' => [['version' => 1, 'remoteRefs' => ['allow' => [1, 'schemas.example.com/']], 'sources' => [$source]], 'A remote $ref prefix must be an absolute http or https URL', '/remoteRefs/allow/1'],
            'extension after a bad entry' => [['version' => 1, 'extensions' => [1, 'Not A Class'], 'sources' => [$source]], 'is not a valid class name', '/extensions/1'],
            'discoverExtensions list' => [['version' => 1, 'discoverExtensions' => ['a/b'], 'sources' => [$source]], '"discoverExtensions" must be true, false or an object', '/discoverExtensions'],
            'discoverExtensions key' => [['version' => 1, 'discoverExtensions' => ['skip' => []], 'sources' => [$source]], 'Unknown key "skip"', '/discoverExtensions/skip'],
            'exclude not a list' => [['version' => 1, 'discoverExtensions' => ['exclude' => 'a/b'], 'sources' => [$source]], '"exclude" must be a list of strings', '/discoverExtensions/exclude'],
            'exclude not a string' => [['version' => 1, 'discoverExtensions' => ['exclude' => ['a/b', 1]], 'sources' => [$source]], 'Expected a non-empty string', '/discoverExtensions/exclude/1'],
            'exclude upper-case package' => [['version' => 1, 'discoverExtensions' => ['exclude' => ['Acme/Pkg']], 'sources' => [$source]], 'Expected a package name in lower case (vendor/name) or an extension class', '/discoverExtensions/exclude/0'],
            'exclude package of three parts' => [['version' => 1, 'discoverExtensions' => ['exclude' => ['a/b/c']], 'sources' => [$source]], 'Expected a package name in lower case (vendor/name) or an extension class', '/discoverExtensions/exclude/0'],
            'exclude bad class' => [['version' => 1, 'discoverExtensions' => ['exclude' => ['a/b', 'Not A Class']], 'sources' => [$source]], 'Expected a package name in lower case (vendor/name) or an extension class', '/discoverExtensions/exclude/1'],
            'bad extension class' => [['version' => 1, 'extensions' => ['Not A Class'], 'sources' => [$source]], 'is not a valid class name', '/extensions/0'],
            'missing sources' => [['version' => 1], '"sources" is required', ''],
            'empty sources' => [['version' => 1, 'sources' => []], '"sources" must be a non-empty list', '/sources'],
            'source not object' => [['version' => 1, 'sources' => ['a.yaml']], 'Expected an object', '/sources/0'],
            'source missing spec' => [['version' => 1, 'sources' => [['namespace' => 'App', 'outputDir' => 'src']]], '"spec" is required', '/sources/0'],
            'unknown source key' => [['version' => 1, 'sources' => [$source + ['output' => 'x']]], 'Unknown key "output"', '/sources/0/output'],
            'bad namespace' => [['version' => 1, 'sources' => [['namespace' => 'App\1Dto'] + $source]], 'is not a valid namespace', '/sources/0/namespace'],
            'empty include' => [['version' => 1, 'sources' => [$source + ['include' => []]]], '"include" must not be empty', '/sources/0/include'],
            'include not strings' => [['version' => 1, 'sources' => [$source + ['include' => [1]]]], 'Expected a non-empty string', '/sources/0/include/0'],
            'readWriteModels' => [['version' => 1, 'dto' => ['readWriteModels' => 'both'], 'sources' => [$source]], '"readWriteModels" must be one of', '/dto/readWriteModels'],
            'readWriteSuffixes key' => [['version' => 1, 'dto' => ['readWriteSuffixes' => ['in' => 'In']], 'sources' => [$source]], 'Unknown key "in"', '/dto/readWriteSuffixes/in'],
            'readWriteSuffixes not identifier' => [['version' => 1, 'dto' => ['readWriteSuffixes' => ['read' => 'Read-Model']], 'sources' => [$source]], '"read" must be a PHP identifier', '/dto/readWriteSuffixes/read'],
            'readWriteSuffixes empty' => [['version' => 1, 'dto' => ['readWriteSuffixes' => ['write' => '']], 'sources' => [$source]], '"write" must be a PHP identifier', '/dto/readWriteSuffixes/write'],
            'readWriteSuffixes same' => [['version' => 1, 'dto' => ['readWriteSuffixes' => ['read' => 'Model', 'write' => 'Model']], 'sources' => [$source]], 'The read and write suffixes must differ', '/dto/readWriteSuffixes'],
            'readWriteSuffixes same but case' => [['version' => 1, 'dto' => ['readWriteModels' => 'split', 'readWriteSuffixes' => ['read' => 'Dto', 'write' => 'DTO']], 'sources' => [$source]], 'The read and write suffixes must differ', '/dto/readWriteSuffixes'],
            'remoteRefs key' => [['version' => 1, 'remoteRefs' => ['deny' => []], 'sources' => [$source]], 'Unknown key "deny"', '/remoteRefs/deny'],
            'remoteRefs prefix not a url' => [['version' => 1, 'remoteRefs' => ['allow' => ['schemas.example.com/']], 'sources' => [$source]], 'A remote $ref prefix must be an absolute http or https URL without query or fragment.', '/remoteRefs/allow/0'],
            'remoteRefs prefix with query' => [['version' => 1, 'remoteRefs' => ['allow' => ['https://example.com/?v=1']], 'sources' => [$source]], 'A remote $ref prefix must be an absolute http or https URL without query or fragment.', '/remoteRefs/allow/0'],
            'remoteRefs prefix with fragment' => [['version' => 1, 'remoteRefs' => ['allow' => ['https://example.com/#x']], 'sources' => [$source]], 'A remote $ref prefix must be an absolute http or https URL without query or fragment.', '/remoteRefs/allow/0'],
            'remoteRefs prefix with credentials' => [['version' => 1, 'remoteRefs' => ['allow' => ['https://u:p@example.com/']], 'sources' => [$source]], 'credentials in a URL are not supported', '/remoteRefs/allow/0'],
            'remoteRefs cacheDir' => [['version' => 1, 'remoteRefs' => ['cacheDir' => ''], 'sources' => [$source]], '"cacheDir" must be a non-empty string', '/remoteRefs/cacheDir'],
            'remoteRefs timeout zero' => [['version' => 1, 'remoteRefs' => ['timeout' => 0], 'sources' => [$source]], '"timeout" must be a number of seconds from 1 to 300', '/remoteRefs/timeout'],
            'remoteRefs timeout large' => [['version' => 1, 'remoteRefs' => ['timeout' => 301], 'sources' => [$source]], '"timeout" must be a number of seconds from 1 to 300', '/remoteRefs/timeout'],
            'remoteRefs timeout text' => [['version' => 1, 'remoteRefs' => ['timeout' => '10'], 'sources' => [$source]], '"timeout" must be a number of seconds from 1 to 300', '/remoteRefs/timeout'],
            'remoteRefs headers not a list' => [['version' => 1, 'remoteRefs' => ['headers' => ['name' => 'X']], 'sources' => [$source]], '"headers" must be a non-empty list', '/remoteRefs/headers'],
            'remoteRefs header key' => [['version' => 1, 'remoteRefs' => ['headers' => [['prefix' => 'https://a.example/', 'name' => 'X', 'value' => 'v', 'secret' => 'x']]], 'sources' => [$source]], 'Unknown key "secret"', '/remoteRefs/headers/0/secret'],
            'remoteRefs header without a name' => [['version' => 1, 'remoteRefs' => ['headers' => [['prefix' => 'https://a.example/', 'value' => 'v']]], 'sources' => [$source]], '"name" is required.', '/remoteRefs/headers/0'],
            'remoteRefs header without a value' => [['version' => 1, 'remoteRefs' => ['headers' => [['prefix' => 'https://a.example/', 'name' => 'X']]], 'sources' => [$source]], '"value" is required.', '/remoteRefs/headers/0'],
            'remoteRefs header without a prefix' => [['version' => 1, 'remoteRefs' => ['headers' => [['name' => 'X', 'value' => 'v']]], 'sources' => [$source]], '"prefix" is required.', '/remoteRefs/headers/0'],
            'remoteRefs header prefix not a url' => [['version' => 1, 'remoteRefs' => ['headers' => [['prefix' => 'a.example/', 'name' => 'X', 'value' => 'v']]], 'sources' => [$source]], 'A header prefix must be an absolute https URL without query or fragment.', '/remoteRefs/headers/0/prefix'],
            'remoteRefs header prefix with query' => [['version' => 1, 'remoteRefs' => ['headers' => [['prefix' => 'https://a.example/?x', 'name' => 'X', 'value' => 'v']]], 'sources' => [$source]], 'A header prefix must be an absolute https URL without query or fragment.', '/remoteRefs/headers/0/prefix'],
            'remoteRefs header prefix with fragment' => [['version' => 1, 'remoteRefs' => ['headers' => [['prefix' => 'https://a.example/#x', 'name' => 'X', 'value' => 'v']]], 'sources' => [$source]], 'A header prefix must be an absolute https URL without query or fragment.', '/remoteRefs/headers/0/prefix'],
            'remoteRefs header prefix with credentials' => [['version' => 1, 'remoteRefs' => ['headers' => [['prefix' => 'https://u@a.example/', 'name' => 'X', 'value' => 'v']]], 'sources' => [$source]], 'credentials in a URL are not supported', '/remoteRefs/headers/0/prefix'],
            'remoteRefs header over http' => [['version' => 1, 'remoteRefs' => ['headers' => [['prefix' => 'http://a.example/', 'name' => 'X', 'value' => 'v']]], 'sources' => [$source]], 'A header is sent only over https, not to "http://a.example/".', '/remoteRefs/headers/0/prefix'],
            'remoteRefs header name' => [['version' => 1, 'remoteRefs' => ['headers' => [['prefix' => 'https://a.example/', 'name' => 'Host', 'value' => 'v']]], 'sources' => [$source]], 'The header Host is written by the generator itself.', '/remoteRefs/headers/0'],
            'remoteRefs header value not a string' => [['version' => 1, 'remoteRefs' => ['headers' => [['prefix' => 'https://a.example/', 'name' => 'X', 'value' => 2]]], 'sources' => [$source]], '"value" must be a non-empty string', '/remoteRefs/headers/0/value'],
            'withers not bool' => [['version' => 1, 'dto' => ['withers' => 'no'], 'sources' => [$source]], '"withers" must be true or false', '/dto/withers'],
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

    public function testReadsTheReadAndWriteModels(): void
    {
        $source = ['spec' => '/abs/openapi.yaml', 'namespace' => 'App\Dto', 'outputDir' => 'src/Dto'];
        $default = $this->valid(['version' => 1, 'sources' => [$source]]);
        $split = $this->valid(['version' => 1, 'dto' => ['readWriteModels' => 'split', 'readWriteSuffixes' => ['read' => 'Response', 'write' => 'Request']], 'sources' => [$source]]);
        $partly = $this->valid(['version' => 1, 'dto' => ['readWriteModels' => 'split', 'readWriteSuffixes' => ['write' => 'Input']], 'sources' => [$source]]);
        $single = $this->valid(['version' => 1, 'dto' => ['readWriteModels' => 'single'], 'sources' => [$source]]);

        self::assertFalse($default->dto()->readWriteModels()->isSplit());
        self::assertSame(['Read', 'Write'], [$default->dto()->viewSuffixes()->read(), $default->dto()->viewSuffixes()->write()]);
        self::assertTrue($split->dto()->readWriteModels()->isSplit());
        self::assertSame(['Response', 'Request'], [$split->dto()->viewSuffixes()->read(), $split->dto()->viewSuffixes()->write()]);
        self::assertSame(['Read', 'Input'], [$partly->dto()->viewSuffixes()->read(), $partly->dto()->viewSuffixes()->write()]);
        self::assertFalse($single->dto()->readWriteModels()->isSplit());
        self::assertSame('split', $split->dto()->readWriteModels()->value());
    }

    public function testTakesSuffixesForTheSchemasThatSplitThemselves(): void
    {
        $config = $this->valid(['version' => 1, 'dto' => ['readWriteSuffixes' => ['read' => 'Out']], 'sources' => [['spec' => '/abs/openapi.yaml', 'namespace' => 'App\Dto', 'outputDir' => 'src/Dto']]]);

        self::assertSame('Out', $config->dto()->viewSuffixes()->read());
    }

    public function testReportsEveryPrefixItCannotUse(): void
    {
        $diagnostics = new Diagnostics();
        (new ConfigFactory())->create(
            ['version' => 1, 'remoteRefs' => ['allow' => ['b/', 'ftp://a/', 'https://ok.example.com/']], 'sources' => [['spec' => '/abs/openapi.yaml', 'namespace' => 'App\\Dto', 'outputDir' => 'src/Dto']]],
            self::PATH,
            $diagnostics,
        );

        self::assertSame([
            'error ' . self::PATH . '#/remoteRefs/allow/0: A remote $ref prefix must be an absolute http or https URL without query or fragment.',
            'error ' . self::PATH . '#/remoteRefs/allow/1: URL "ftp://a/": only http and https are supported.',
        ], array_map(static fn (Diagnostic $d): string => $d->toString(), $diagnostics->all()));
    }

    public function testReadsTheRemoteRefs(): void
    {
        $source = ['spec' => '/abs/openapi.yaml', 'namespace' => 'App\\Dto', 'outputDir' => 'src/Dto'];
        $default = $this->valid(['version' => 1, 'sources' => [$source]]);
        $set = $this->valid(['version' => 1, 'remoteRefs' => ['allow' => ['HTTPS://Schemas.Example.com/common/', 'http://localhost:8080/'], 'cacheDir' => 'var/remote', 'timeout' => 1], 'sources' => [$source]]);
        $longest = $this->valid(['version' => 1, 'remoteRefs' => ['timeout' => 300], 'sources' => [$source]]);

        self::assertSame([], $default->remoteRefs()->allow());
        self::assertSame(dirname(self::PATH) . '/.dto-generator/remote', $default->remoteRefs()->cacheDir());
        self::assertSame(10, $default->remoteRefs()->timeout());
        self::assertSame(['https://schemas.example.com/common/', 'http://localhost:8080/'], $set->remoteRefs()->allow());
        self::assertSame(dirname(self::PATH) . '/var/remote', $set->remoteRefs()->cacheDir());
        self::assertSame(1, $set->remoteRefs()->timeout());
        self::assertSame(300, $longest->remoteRefs()->timeout());
    }

    public function testReadsTheHeadersOfRemoteDocuments(): void
    {
        $source = ['spec' => '/abs/openapi.yaml', 'namespace' => 'App\\Dto', 'outputDir' => 'src/Dto'];
        $config = $this->valid(['version' => 1, 'remoteRefs' => ['allow' => ['https://git.example.com/api/v4/', 'https://other.example.com/'], 'headers' => [
            ['prefix' => 'HTTPS://Git.Example.com/api/', 'name' => 'PRIVATE-TOKEN', 'value' => '${GITLAB_TOKEN}'],
            ['prefix' => 'https://other.example.com/schemas/', 'name' => 'Accept', 'value' => 'application/json'],
            ['prefix' => 'https://git.example.com/api/v4/x/', 'name' => 'private-token', 'value' => 'inner'],
        ]], 'sources' => [$source]]);

        $git = $config->remoteRefs()->headersFor('https://git.example.com/api/v4/a.yaml');
        self::assertCount(1, $git);
        self::assertSame('https://git.example.com/api/', $git[0]->prefix());
        self::assertSame('PRIVATE-TOKEN', $git[0]->name());
        self::assertSame(['GITLAB_TOKEN'], $git[0]->variables());
        self::assertSame('inner', $config->remoteRefs()->headersFor('https://git.example.com/api/v4/x/a.yaml')[0]->render([]));
        self::assertSame('application/json', $config->remoteRefs()->headersFor('https://other.example.com/schemas/a.yaml')[0]->render([]));
    }

    public function testWarnsAboutAHeaderNoAllowedUrlGets(): void
    {
        $diagnostics = new Diagnostics();
        $config = (new ConfigFactory())->create(
            ['version' => 1, 'remoteRefs' => ['allow' => ['https://a.example/schemas/'], 'headers' => [
                ['prefix' => 'https://b.example/', 'name' => 'X', 'value' => 'v'],
                ['prefix' => 'https://a.example/other/', 'name' => 'Y', 'value' => 'v'],
            ]], 'sources' => [['spec' => '/abs/openapi.yaml', 'namespace' => 'App\\Dto', 'outputDir' => 'src/Dto']]],
            self::PATH,
            $diagnostics,
        );

        self::assertNotNull($config);
        self::assertSame([
            'warning ' . self::PATH . '#/remoteRefs/headers/0/prefix: No remoteRefs.allow prefix overlaps https://b.example/, so the header X is never sent.',
            'warning ' . self::PATH . '#/remoteRefs/headers/1/prefix: No remoteRefs.allow prefix overlaps https://a.example/other/, so the header Y is never sent.',
        ], array_map(static fn (Diagnostic $d): string => $d->toString(), $diagnostics->all()));
    }

    public function testReportsEveryHeaderItCannotUse(): void
    {
        $diagnostics = new Diagnostics();
        (new ConfigFactory())->create(
            ['version' => 1, 'remoteRefs' => ['allow' => ['https://a.example/'], 'headers' => [
                ['name' => 'X', 'value' => 'v'],
                ['prefix' => 'a.example/', 'name' => 'X', 'value' => 'v'],
                ['prefix' => 'http://a.example/', 'name' => 'X', 'value' => 'v'],
                'text',
                ['prefix' => 'https://a.example/', 'name' => 'Y', 'value' => '${'],
                ['prefix' => 'https://a.example/', 'name' => 'z', 'value' => 'one'],
                ['prefix' => 'HTTPS://A.example/', 'name' => 'Z', 'value' => 'two'],
                ['prefix' => 'https://a.example/', 'name' => 'W', 'value' => 'one'],
                ['prefix' => 'https://a.example/', 'name' => 'w', 'value' => 'two'],
                ['prefix' => 'https://u@a.example/', 'name' => 'X', 'value' => 'v'],
                ['prefix' => 'https://a.example/', 'name' => 'X', 'value' => '${HTTP_TOKEN}'],
            ]], 'sources' => [['spec' => '/abs/openapi.yaml', 'namespace' => 'App\\Dto', 'outputDir' => 'src/Dto']]],
            self::PATH,
            $diagnostics,
        );

        self::assertSame([
            'error ' . self::PATH . '#/remoteRefs/headers/3: Expected an object.',
            'error ' . self::PATH . '#/remoteRefs/headers/0: "prefix" is required.',
            'error ' . self::PATH . '#/remoteRefs/headers/1/prefix: A header prefix must be an absolute https URL without query or fragment.',
            'error ' . self::PATH . '#/remoteRefs/headers/2/prefix: A header is sent only over https, not to "http://a.example/".',
            'error ' . self::PATH . '#/remoteRefs/headers/4: The value of the header Y has a "${" that does not start a variable such as ${NAME}.',
            'error ' . self::PATH . '#/remoteRefs/headers/6: Another entry already sets z for https://a.example/.',
            'error ' . self::PATH . '#/remoteRefs/headers/8: Another entry already sets W for https://a.example/.',
            'error ' . self::PATH . '#/remoteRefs/headers/9/prefix: URL "https://u@a.example/": credentials in a URL are not supported.',
            'error ' . self::PATH . '#/remoteRefs/headers/10: The value of the header X names HTTP_TOKEN, but a variable named HTTP_* can come from a request header.',
        ], array_map(static fn (Diagnostic $d): string => $d->toString(), $diagnostics->all()));
    }

    public function testReadsWithersOff(): void
    {
        $config = $this->valid(['version' => 1, 'dto' => ['withers' => false], 'sources' => [['spec' => '/abs/openapi.yaml', 'namespace' => 'App\Dto', 'outputDir' => 'src/Dto']]]);

        self::assertFalse($config->dto()->hasWithers());
    }

    public function testWarnsThatWithersOffLeavesMutableDtosAlone(): void
    {
        $diagnostics = new Diagnostics();
        $config = (new ConfigFactory())->create(
            ['version' => 1, 'dto' => ['mutability' => 'mutable', 'withers' => false], 'sources' => [['spec' => '/abs/openapi.yaml', 'namespace' => 'App\Dto', 'outputDir' => 'src/Dto']]],
            self::PATH,
            $diagnostics,
        );

        self::assertNotNull($config);
        self::assertSame(
            ['warning ' . self::PATH . '#/dto/withers: "withers: false" has no effect on mutable DTOs, which keep their setters; it applies to schemas made immutable with x-dto-mutable: false.'],
            array_map(static fn (Diagnostic $d): string => $d->toString(), $diagnostics->all()),
        );
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

    /**
     * @dataProvider discoverySettings
     *
     * @param bool|array<string, list<string>> $discover
     * @param list<string> $excluded
     */
    public function testReadsWhichDiscoveredExtensionsAreLeftOut($discover, bool $on, array $excluded): void
    {
        $settings = $this->valid(['discoverExtensions' => $discover] + self::MINIMAL)->extensions();

        self::assertSame($on, $settings->discover());
        self::assertSame($excluded, array_map(static fn (ExcludedExtension $entry): string => $entry->index() . ' ' . $entry->describe(), $settings->excluded()));
    }

    /**
     * @return iterable<string, array{bool|array<string, list<string>>, bool, list<string>}>
     */
    public static function discoverySettings(): iterable
    {
        yield 'on' => [true, true, []];
        yield 'off' => [false, false, []];
        yield 'an empty object' => [[], true, []];
        yield 'packages and classes' => [['exclude' => ['acme/money-ext', '\Vendor\Ext\Audit', 'a.b-c/d_e--f']], true, ['0 acme/money-ext', '1 Vendor\Ext\Audit', '2 a.b-c/d_e--f']];
    }

    public function testKeepsTheValidPatternsOfASourceAsAList(): void
    {
        $diagnostics = new Diagnostics();
        $config = (new ConfigFactory())->create(['version' => 1, 'sources' => [['spec' => 'a.yaml', 'namespace' => 'App\Dto', 'outputDir' => 'src', 'include' => [1, 'User*'], 'exclude' => [2, 'UserInternal']]]], self::PATH, $diagnostics);

        self::assertNull($config);
        self::assertSame(
            ['/sources/0/include/0', '/sources/0/exclude/0'],
            array_map(static fn (Diagnostic $error): string => substr($error->location()->toString(), strlen(self::PATH . '#')), $diagnostics->errors()),
        );
    }

    public function testReadsTheNamesASourceGivesItsSchemas(): void
    {
        $diagnostics = new Diagnostics();
        $config = (new ConfigFactory())->create(['version' => 1, 'sources' => [[
            'spec' => '../api/a.yaml', 'namespace' => 'App\Dto', 'outputDir' => 'src',
            'classNames' => ['#/components/schemas/fields' => 'FieldList', 'common.json#/Type' => 'IssueType'],
            'propertyNames' => ['#/components/schemas/Fields/properties/issueType' => 'issueTypeObject'],
        ]]], self::PATH, $diagnostics);

        self::assertSame([], $diagnostics->all());
        self::assertInstanceOf(GeneratorConfig::class, $config);
        self::assertSame(
            [
                ['x-php-class-name', '/project/api/a.yaml#/components/schemas/fields', 'FieldList', self::PATH . '#/sources/0/classNames/#~1components~1schemas~1fields'],
                ['x-php-class-name', '/project/api/common.json#/Type', 'IssueType', self::PATH . '#/sources/0/classNames/common.json#~1Type'],
                ['x-php-name', '/project/api/a.yaml#/components/schemas/Fields/properties/issueType', 'issueTypeObject', self::PATH . '#/sources/0/propertyNames/#~1components~1schemas~1Fields~1properties~1issueType'],
            ],
            array_map(
                static fn (NameOverride $name): array => [$name->keyword(), $name->target()->toString(), $name->name(), $name->declaredAt()->toString()],
                $config->sources()[0]->nameOverrides(),
            ),
        );
    }

    public function testReportsMalformedNamesOfASource(): void
    {
        $diagnostics = new Diagnostics();
        $config = (new ConfigFactory())->create(['version' => 1, 'sources' => [[
            'spec' => 'a.yaml', 'namespace' => 'App\Dto', 'outputDir' => 'src',
            'classNames' => ['#anchor' => 'A', '#/a' => 1, '#/b' => '', '#/c' => 'C'],
            'propertyNames' => ['x'],
        ]]], self::PATH, $diagnostics);

        self::assertNull($config);
        self::assertSame(
            [
                'error ' . self::PATH . '#/sources/0/classNames/#anchor: Expected a reference to a schema of the specification, like "#/components/schemas/Pet": $ref "#anchor" names an anchor; a JSON pointer ("#/components/schemas/Pet") is expected here.',
                'error ' . self::PATH . '#/sources/0/classNames/#~1a: Expected a non-empty string.',
                'error ' . self::PATH . '#/sources/0/classNames/#~1b: Expected a non-empty string.',
                'error ' . self::PATH . '#/sources/0/propertyNames: "propertyNames" must be an object.',
            ],
            array_map(static fn (Diagnostic $error): string => $error->toString(), $diagnostics->all()),
        );
    }

    public function testChecksTheNamesOfASourceWithOtherErrors(): void
    {
        $diagnostics = new Diagnostics();
        (new ConfigFactory())->create(['version' => 1, 'sources' => [['spec' => 'a.yaml', 'outputDir' => 'src', 'classNames' => ['#/a' => '', '#anchor' => 'A']]]], self::PATH, $diagnostics);

        self::assertSame(
            ['/sources/0', '/sources/0/classNames/#~1a', '/sources/0/classNames/#anchor'],
            array_map(static fn (Diagnostic $error): string => substr($error->location()->toString(), strlen(self::PATH . '#')), $diagnostics->errors()),
        );
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
