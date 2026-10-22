<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Application\Service\Schemas;

use MSSTC4PHP\DtoGenerator\Application\Config\NameOverride;
use MSSTC4PHP\DtoGenerator\Application\Config\SourceConfig;
use MSSTC4PHP\DtoGenerator\Application\Port\DocumentLoadFailed;
use MSSTC4PHP\DtoGenerator\Application\Service\Schemas\Load\Action;
use MSSTC4PHP\DtoGenerator\Application\Service\Schemas\Load\Input;
use MSSTC4PHP\DtoGenerator\Application\Service\Schemas\Load\Output;
use MSSTC4PHP\DtoGenerator\Domain\Builder\SchemaParser;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\ClaimedKeyword;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostic;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\KeywordChecks;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Reference;
use MSSTC4PHP\DtoGenerator\Domain\Schema\ReferenceUse;
use MSSTC4PHP\DtoGenerator\Domain\Schema\ResolvedSchema;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;
use MSSTC4PHP\DtoGenerator\Tests\Support\ConfigMother;
use MSSTC4PHP\DtoGenerator\Tests\Support\InMemoryDocumentLoader;
use PHPUnit\Framework\TestCase;

/**
 * @phpstan-import-type JsonValue from Json
 */
final class LoadTest extends TestCase
{
    private const SPEC = '/project/api/openapi.yaml';

    private const OTHER = '/project/other/openapi.yaml';

    private const SHARED = '/project/shared/common.json';

    public function testLoadsSelectedComponents(): void
    {
        $output = $this->load([self::SPEC => $this->spec(['User' => ['type' => 'object'], 'Tag' => ['type' => 'object']])]);

        self::assertSame([], $this->messages($output));
        self::assertSame([['User', 0, true], ['Tag', 0, true]], $this->summary($output));
        self::assertSame('/project/api/openapi.yaml#/components/schemas/User', $output->graph()->all()[0]->location()->toString());
    }

    public function testAppliesIncludeAndExcludeGlobs(): void
    {
        $output = $this->load(
            [self::SPEC => $this->spec(['User' => [], 'UserInternal' => [], 'Tag' => [], 'Order' => []])],
            ConfigMother::source(self::SPEC, ['U*', 'Tag'], ['*Internal']),
        );

        self::assertSame([['User', 0, true], ['Tag', 0, true]], $this->summary($output));
    }

    public function testLoadsReferencedButUnselectedComponents(): void
    {
        $output = $this->load(
            [self::SPEC => $this->spec([
                'User' => ['properties' => ['tag' => ['$ref' => '#/components/schemas/Tag']]],
                'Tag' => ['type' => 'object'],
            ])],
            ConfigMother::source(self::SPEC, ['User']),
        );

        self::assertSame([['User', 0, true], ['Tag', 0, false]], $this->summary($output));
    }

    public function testExternalFilesBelongToTheReferencingSource(): void
    {
        $output = $this->load([
            self::SPEC => $this->spec(['User' => ['properties' => ['salary' => ['$ref' => '../shared/common.json#/Money']]]]),
            self::SHARED => [
                'Money' => ['properties' => ['currency' => ['$ref' => '#/Currency']]],
                'Currency' => ['type' => 'string'],
            ],
        ]);

        self::assertSame([], $this->messages($output));
        self::assertSame([['User', 0, true], ['Money', 0, false], ['Currency', 0, false]], $this->summary($output));
    }

    public function testAFileOfAnotherSourceKeepsItsOwnerAndIsLoadedOnce(): void
    {
        $output = $this->load(
            [
                self::SPEC => $this->spec(['User' => ['properties' => ['pet' => ['$ref' => '../other/openapi.yaml#/components/schemas/Pet']]]]),
                self::OTHER => $this->spec(['Pet' => ['type' => 'object']]),
            ],
            ConfigMother::source(self::SPEC),
            ConfigMother::source('/project/./other/../other/openapi.yaml', ['*'], [], 'App\Other'),
        );

        self::assertSame([], $this->messages($output));
        self::assertSame([['User', 0, true], ['Pet', 1, true]], $this->summary($output));
    }

    public function testASchemaSharedByTwoSourcesOutsideBothIsAnError(): void
    {
        $output = $this->load(
            [
                self::SPEC => $this->spec(['User' => ['$ref' => '../shared/common.json#/Money']]),
                self::OTHER => $this->spec(['Pet' => ['$ref' => '../shared/common.json#/Money']]),
                self::SHARED => ['Money' => ['type' => 'object']],
            ],
            ConfigMother::source(self::SPEC),
            ConfigMother::source(self::OTHER, ['*'], [], 'App\Other'),
        );

        self::assertSame(
            ['error /project/shared/common.json#/Money: Schema is referenced from sources #0, #1, so its namespace is ambiguous; add its file as a source.'],
            $this->messages($output),
        );
    }

    public function testWarnsOncePerSchemaReachedTwice(): void
    {
        $output = $this->load([
            self::SPEC => $this->spec([
                'Odd' => ['type' => 'object', 'not' => ['required' => ['b']]],
                'X' => ['properties' => [
                    'one' => ['$ref' => '#/components/schemas/Odd'],
                    'two' => ['$ref' => '#/components/schemas/Odd'],
                    'three' => ['$ref' => 'shared.yaml#/Far'],
                    'four' => ['$ref' => 'shared.yaml#/Far'],
                ]],
            ]),
            '/project/api/shared.yaml' => ['Far' => ['type' => 'string', 'nullable' => true]],
        ]);

        self::assertSame(
            [
                'warning /project/api/openapi.yaml#/components/schemas/Odd/not: "not" is not supported and has no effect on the generated type.',
                'warning /project/api/shared.yaml#/Far/nullable: "nullable" is OpenAPI 3.0 and has no effect in 3.1; write type: [T, \'null\'].',
            ],
            $this->messages($output),
        );
    }

    public function testLeavesTheClaimedKeywordsToTheirExtension(): void
    {
        $documents = [self::SPEC => $this->spec(['Odd' => ['type' => 'array', 'contains' => ['const' => 1], 'not' => ['type' => 'null']]])];
        $config = ConfigMother::config(ConfigMother::source(self::SPEC));
        $checks = new KeywordChecks(['contains']);
        $output = (new Action(new InMemoryDocumentLoader($documents), new SchemaParser()))(new Input($config, null, $checks));

        self::assertSame(['warning /project/api/openapi.yaml#/components/schemas/Odd/not: "not" is not supported and has no effect on the generated type.'], $this->messages($output));
        self::assertSame([['contains', '/project/api/openapi.yaml#/components/schemas/Odd']], array_map(static fn (ClaimedKeyword $pending): array => [$pending->keyword(), $pending->schema()->toString()], $checks->unchecked()));
    }

    public function testFollowsCyclesOnce(): void
    {
        $output = $this->load([self::SPEC => $this->spec([
            'User' => ['properties' => ['group' => ['$ref' => '#/components/schemas/Group'], 'self' => ['$ref' => '#/components/schemas/User']]],
            'Group' => ['properties' => ['members' => ['items' => ['$ref' => '#/components/schemas/User']]]],
        ])]);

        self::assertSame([], $this->messages($output));
        self::assertSame([['User', 0, true], ['Group', 0, true]], $this->summary($output));
    }

    public function testResolvesEscapedReferences(): void
    {
        $output = $this->load(
            [self::SPEC => $this->spec([
                'User' => ['properties' => ['a' => ['$ref' => '#/components/schemas/My%20Type'], 'b' => ['$ref' => '#/components/schemas/a~1b']]],
                'My Type' => ['type' => 'string'],
                'a/b' => ['type' => 'string'],
            ])],
            ConfigMother::source(self::SPEC, ['User']),
        );

        self::assertSame([], $this->messages($output));
        self::assertSame([['User', 0, true], ['My Type', 0, false], ['a/b', 0, false]], $this->summary($output));
    }

    public function testFollowsBareDiscriminatorNames(): void
    {
        $output = $this->load(
            [self::SPEC => $this->spec([
                'Pet' => ['discriminator' => ['propertyName' => 'kind', 'mapping' => ['cat' => 'Cat']]],
                'Cat' => ['type' => 'object'],
            ])],
            ConfigMother::source(self::SPEC, ['Pet']),
        );

        self::assertSame([['Pet', 0, true], ['Cat', 0, false]], $this->summary($output));
    }

    public function testFollowsTheDefaultMappingAndReportsOneThatNamesNothing(): void
    {
        $output = $this->load(
            [self::SPEC => $this->spec([
                'Pet' => ['discriminator' => ['propertyName' => 'kind', 'defaultMapping' => 'Other']],
                'Cat' => ['discriminator' => ['propertyName' => 'kind', 'defaultMapping' => '#/components/schemas/Missing']],
                'Other' => ['type' => 'object'],
            ])],
            ConfigMother::source(self::SPEC, ['Pet', 'Cat']),
        );

        self::assertSame([['Pet', 0, true], ['Cat', 0, true], ['Other', 0, false]], $this->summary($output));
        self::assertCount(1, $this->messages($output));
        self::assertStringStartsWith('error /project/api/openapi.yaml#/components/schemas/Cat/discriminator/defaultMapping: ', $this->messages($output)[0]);
    }

    public function testNamesWholeFileTargetsAfterTheFile(): void
    {
        $output = $this->load([
            self::SPEC => $this->spec(['User' => ['$ref' => '../shared/address.yaml']]),
            '/project/shared/address.yaml' => ['type' => 'object'],
        ]);

        self::assertSame([['User', 0, true], ['address', 0, false]], $this->summary($output));
    }

    /**
     * @dataProvider brokenReferences
     *
     * @param array<array-key, mixed> $user
     */
    public function testReportsBrokenReferencesWhereTheyAreWritten(array $user, string $expected): void
    {
        $output = $this->load([self::SPEC => $this->spec(['User' => $user])]);

        self::assertSame([$expected], $this->messages($output));
    }

    /**
     * @return array<string, array{array<array-key, mixed>, string}>
     */
    public static function brokenReferences(): array
    {
        $at = 'error /project/api/openapi.yaml#/components/schemas/User/properties/x: ';

        return [
            'remote' => [
                ['properties' => ['x' => ['$ref' => 'https://example.com/x.json']]],
                $at . 'Remote $ref "https://example.com/x.json" names https://example.com/x.json, which remoteRefs.allow does not cover; add its prefix there, or save the document next to the specification and refer to it by path.',
            ],
            'missing pointer' => [
                ['properties' => ['x' => ['$ref' => '#/components/schemas/Nope']]],
                $at . '$ref "#/components/schemas/Nope" does not resolve: /project/api/openapi.yaml has nothing at "/components/schemas/Nope".',
            ],
            'missing file' => [
                ['properties' => ['x' => ['$ref' => 'missing.yaml#/X']]],
                $at . 'File "/project/api/missing.yaml" does not exist.',
            ],
            'missing anchor' => [
                ['properties' => ['x' => ['$ref' => '#User']]],
                $at . '$ref "#User" does not resolve: /project/api/openapi.yaml has no schema with "$anchor": "User".',
            ],
            'ambiguous anchor' => [
                ['properties' => ['x' => ['$ref' => '#Twice'], 'y' => ['$anchor' => 'Twice'], 'z' => ['items' => ['$anchor' => 'Twice']]]],
                $at . '$ref "#Twice" is ambiguous: "Twice" is the anchor of /project/api/openapi.yaml#/components/schemas/User/properties/y, /project/api/openapi.yaml#/components/schemas/User/properties/z/items.',
            ],
            'no anchor name' => [
                ['properties' => ['x' => ['$ref' => '#1st']]],
                $at . '$ref "#1st": "1st" is neither a JSON pointer nor an anchor name.',
            ],
            'anchor in a missing file' => [
                ['properties' => ['x' => ['$ref' => 'missing.yaml#X']]],
                $at . 'File "/project/api/missing.yaml" does not exist.',
            ],
            'remote anchor' => [
                ['properties' => ['x' => ['$ref' => 'https://example.com/x.json#X']]],
                $at . 'Remote $ref "https://example.com/x.json#X" names https://example.com/x.json, which remoteRefs.allow does not cover; add its prefix there, or save the document next to the specification and refer to it by path.',
            ],
        ];
    }

    public function testFollowsAReferenceToAnAnchor(): void
    {
        $output = $this->load([
            self::SPEC => $this->spec([
                'User' => ['properties' => [
                    'address' => ['$ref' => '#Address'],
                    'home' => ['$ref' => '#/components/schemas/Address'],
                    'tag' => ['$ref' => '../shared/common.yaml#Tag'],
                    'note' => ['$ref' => '#Note'],
                ], 'not' => ['$ref' => '#Hidden']],
                'Address' => ['$anchor' => 'Address', 'type' => 'object'],
                'Holder' => ['type' => 'object', 'properties' => ['n' => ['$anchor' => 'Note', 'type' => 'string']], 'examples' => [['$anchor' => 'Note']], 'x-data' => ['$anchor' => 'Note']],
                'Hidden' => ['$anchor' => 'Hidden', 'type' => 'integer'],
            ]),
            '/project/shared/common.yaml' => ['$defs' => ['Tag' => ['$anchor' => 'Tag', 'type' => 'object']], 'default' => ['$anchor' => 'Tag']],
        ]);

        self::assertSame(['warning /project/api/openapi.yaml#/components/schemas/User/not: "not" is not supported and has no effect on the generated type.'], $this->messages($output));
        self::assertSame(
            [['User', 0, true], ['Address', 0, true], ['Holder', 0, true], ['Hidden', 0, true], ['Tag', 0, false], ['n', 0, false]],
            $this->summary($output),
        );
        $user = $output->graph()->get(new SchemaLocation(self::SPEC, '/components/schemas/User'));
        self::assertNotNull($user);
        $tag = $output->graph()->resolve($user->schema()->requireProperty('tag')->references()[0]);
        self::assertNotNull($tag);
        self::assertSame('/project/shared/common.yaml#/$defs/Tag', $tag->location()->toString());
    }

    public function testReportsAnAnchorNoSingleSchemaDeclaresOnce(): void
    {
        $output = $this->load([self::SPEC => ['$anchor' => 'Twice'] + $this->spec([
            'User' => ['properties' => ['a' => ['$ref' => '#Gone'], 'b' => ['$ref' => '#Gone'], 'c' => ['$ref' => '#Twice'], 'd' => ['$ref' => '#Twice']]],
            'Other' => ['$anchor' => 'Twice'],
        ])]);

        self::assertSame(
            [
                'error /project/api/openapi.yaml#/components/schemas/User/properties/a: $ref "#Gone" does not resolve: /project/api/openapi.yaml has no schema with "$anchor": "Gone".',
                'error /project/api/openapi.yaml#/components/schemas/User/properties/c: $ref "#Twice" is ambiguous: "Twice" is the anchor of /project/api/openapi.yaml#, /project/api/openapi.yaml#/components/schemas/Other.',
            ],
            $this->messages($output),
        );
    }

    public function testFollowsAnAnchorOfAKeywordOrADiscriminatorMapping(): void
    {
        $output = $this->load([self::SPEC => $this->spec([
            'Pet' => ['oneOf' => [['$ref' => '#Cat']], 'discriminator' => ['propertyName' => 'kind', 'mapping' => ['cat' => '#Cat']]],
            'Cat' => ['$dynamicAnchor' => 'Cat', 'type' => 'object'],
            'User' => ['type' => 'object', 'not' => ['$ref' => '#Missing']],
        ])]);

        self::assertSame(
            [
                'warning /project/api/openapi.yaml#/components/schemas/User/not: "not" is not supported and has no effect on the generated type.',
                'warning /project/api/openapi.yaml#/components/schemas/User/not: $ref "#Missing" does not resolve: /project/api/openapi.yaml has no schema with "$anchor": "Missing".',
            ],
            $this->messages($output),
        );
        self::assertSame([['Pet', 0, true], ['Cat', 0, true], ['User', 0, true]], $this->summary($output));
    }

    public function testReportsAMissingSpecificationAtTheConfig(): void
    {
        $output = $this->load([]);

        self::assertSame(['error /project/dto-generator.yaml#/sources/0/spec: File "/project/api/openapi.yaml" does not exist.'], $this->messages($output));
    }

    public function testReportsTheSameSpecificationUsedTwice(): void
    {
        $output = $this->load(
            [self::SPEC => $this->spec(['User' => []])],
            ConfigMother::source(self::SPEC),
            ConfigMother::source('/project/api/../api/openapi.yaml', ['*'], [], 'App\Again'),
        );

        self::assertSame(['error /project/dto-generator.yaml#/sources/1/spec: The specification is already used by source #0.'], $this->messages($output));
    }

    /**
     * @param JsonValue $version
     *
     * @dataProvider understoodVersions
     */
    public function testReadsOpenApi30To32WithoutAWarning($version): void
    {
        $output = $this->load([self::SPEC => ['openapi' => $version, 'components' => ['schemas' => []]]]);

        self::assertSame([], $this->messages($output));
    }

    /**
     * @return iterable<string, array{JsonValue}>
     */
    public static function understoodVersions(): iterable
    {
        yield '3.0.3' => ['3.0.3'];
        yield '3.0 from unquoted YAML' => [3.0];
        yield '3.1.1' => ['3.1.1'];
        yield '3.2.0' => ['3.2.0'];
        yield '3.2 from unquoted YAML' => [3.2];
    }

    /**
     * @param JsonValue $self
     *
     * @dataProvider selves
     */
    public function testWarnsAboutASelfThatNamesAnotherUri($self, ?string $shown): void
    {
        $output = $this->load([self::SPEC => ['openapi' => '3.2.0', '$self' => $self, 'components' => ['schemas' => []]]]);

        self::assertSame(
            $shown === null ? [] : ['warning /project/api/openapi.yaml#/$self: "$self" ' . $shown . ' is not read: references in this document resolve against where it was read from, and a "$ref" to that URI does not reach it.'],
            $this->messages($output),
        );
    }

    /**
     * @return iterable<string, array{JsonValue, ?string}>
     */
    public static function selves(): iterable
    {
        yield 'its file name' => ['openapi.yaml', null];
        yield 'its relative path' => ['../api/openapi.yaml', null];
        yield 'its absolute path' => ['/project/api/openapi.yaml', null];
        yield 'another file' => ['public.yaml', '"public.yaml"'];
        yield 'a URL' => ['https://example.com/openapi.yaml', '"https://example.com/openapi.yaml"'];
        yield 'empty, the base URI itself' => ['', null];
        yield 'not a string' => [42, 'that is not a string'];
    }

    /**
     * @dataProvider fragments
     */
    public function testWarnsAboutASelfWithAFragment(string $self): void
    {
        $output = $this->load([self::SPEC => ['openapi' => '3.2.0', '$self' => $self, 'components' => ['schemas' => []]]]);

        self::assertSame(['warning /project/api/openapi.yaml#/$self: "$self" "' . $self . '" must not have a fragment; it is not read.'], $this->messages($output));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function fragments(): iterable
    {
        yield 'an anchor' => ['openapi.yaml#Root'];
        yield 'a pointer' => ['openapi.yaml#/x'];
        yield 'an empty fragment' => ['openapi.yaml#'];
    }

    public function testWarnsOnceAboutTheSelfOfAReferencedDocument(): void
    {
        $output = $this->load([
            self::SPEC => self::spec(['A' => ['$ref' => 'shared.yaml#/Name'], 'B' => ['$ref' => 'shared.yaml#/Name']]),
            '/project/api/shared.yaml' => ['$self' => 'https://example.com/shared.yaml', 'Name' => ['type' => 'string']],
        ]);

        self::assertSame(
            ['warning /project/api/shared.yaml#/$self: "$self" "https://example.com/shared.yaml" is not read: references in this document resolve against where it was read from, and a "$ref" to that URI does not reach it.'],
            $this->messages($output),
        );
    }

    public function testReadsOpenApi32SchemasAs31(): void
    {
        $output = $this->load([self::SPEC => ['openapi' => '3.2.0', 'components' => ['schemas' => ['Name' => ['type' => 'string', 'nullable' => true]]]]]);

        self::assertFalse($output->graph()->all()[0]->schema()->isNullable());
        self::assertSame(['warning /project/api/openapi.yaml#/components/schemas/Name/nullable: "nullable" is OpenAPI 3.0 and has no effect in 3.1; write type: [T, \'null\'].'], $this->messages($output));
    }

    /**
     * @dataProvider otherVersions
     */
    public function testWarnsAboutOtherOpenApiVersions(string $version, string $message): void
    {
        $output = $this->load([self::SPEC => ['openapi' => $version, 'components' => ['schemas' => []]]]);

        self::assertSame(['warning /project/api/openapi.yaml#/openapi: ' . $message], $this->messages($output));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function otherVersions(): iterable
    {
        yield '3.3.0' => ['3.3.0', 'Expected OpenAPI 3.0, 3.1 or 3.2, found "3.3.0"; keywords specific to that version are not understood.'];
        yield '3.0.3 with a suffix' => ['3.0.3-rc', 'Expected OpenAPI 3.0, 3.1 or 3.2, found "3.0.3-rc"; keywords specific to that version are not understood.'];
    }

    public function testReadsNullableInOpenApi30AndInTheDocumentsItRefersTo(): void
    {
        $output = $this->load([
            self::SPEC => ['openapi' => '3.0.3', 'components' => ['schemas' => [
                'User' => ['properties' => [
                    'name' => ['type' => 'string', 'nullable' => true],
                    'near' => ['$ref' => 'shared.yaml#/Near'],
                    'far' => ['$ref' => 'newer.yaml#/Far'],
                ], 'not' => ['$ref' => 'shared.yaml#/Hidden']],
            ]]],
            '/project/api/shared.yaml' => ['Near' => ['type' => 'string', 'nullable' => true], 'Hidden' => ['type' => 'integer', 'nullable' => true]],
            '/project/api/newer.yaml' => ['openapi' => '3.1.0', 'Far' => ['type' => 'string', 'nullable' => true]],
        ]);

        $nullable = [];
        foreach ($output->graph()->all() as $schema) {
            $nullable[$schema->location()->toString()] = $schema->schema()->isNullable();
            foreach ($schema->schema()->propertyNames() as $name) {
                $nullable[$schema->location()->toString() . '/properties/' . $name] = $schema->schema()->requireProperty($name)->isNullable();
            }
        }

        self::assertSame(
            [
                '/project/api/openapi.yaml#/components/schemas/User' => false,
                '/project/api/openapi.yaml#/components/schemas/User/properties/name' => true,
                '/project/api/openapi.yaml#/components/schemas/User/properties/near' => false,
                '/project/api/openapi.yaml#/components/schemas/User/properties/far' => false,
                '/project/api/shared.yaml#/Near' => true,
                '/project/api/newer.yaml#/Far' => false,
                '/project/api/shared.yaml#/Hidden' => true,
            ],
            $nullable,
        );
        self::assertSame(
            [
                'warning /project/api/openapi.yaml#/components/schemas/User/not: "not" is not supported and has no effect on the generated type.',
                'warning /project/api/newer.yaml#/Far/nullable: "nullable" is OpenAPI 3.0 and has no effect in 3.1; write type: [T, \'null\'].',
            ],
            $this->messages($output),
        );
    }

    public function testWarnsAboutAMissingVersionAndMissingSchemas(): void
    {
        $output = $this->load([self::SPEC => ['info' => []]]);

        self::assertSame(
            [
                'warning /project/api/openapi.yaml#/openapi: No "openapi" version; the document is read as OpenAPI 3.1.',
                'warning /project/api/openapi.yaml#/components/schemas: The specification has no components/schemas; nothing to generate.',
            ],
            $this->messages($output),
        );
    }

    public function testRejectsSchemasThatAreNotAnObject(): void
    {
        $output = $this->load([self::SPEC => ['openapi' => '3.1.0', 'components' => ['schemas' => [['type' => 'object'], 'not a schema']]]]);

        self::assertSame(['error /project/api/openapi.yaml#/components/schemas: "schemas" must be an object.'], $this->messages($output));
    }

    /**
     * @param array<string, array<array-key, mixed>|DocumentLoadFailed> $documents
     */
    private function load(array $documents, SourceConfig ...$sources): Output
    {
        $config = $sources === [] ? ConfigMother::config(ConfigMother::source(self::SPEC)) : ConfigMother::config(...$sources);

        return (new Action(new InMemoryDocumentLoader($documents), new SchemaParser()))(new Input($config));
    }

    /**
     * @param array<int|string, array<array-key, mixed>> $schemas
     *
     * @return array<string, mixed>
     */
    private function spec(array $schemas): array
    {
        return ['openapi' => '3.1.0', 'components' => ['schemas' => $schemas]];
    }

    /**
     * @return list<string>
     */
    private function messages(Output $output): array
    {
        return array_map(static fn (Diagnostic $d): string => $d->toString(), $output->diagnostics()->all());
    }

    /**
     * Without the parser's warnings about the keywords no extension claims here.
     *
     * @return list<string>
     */
    private function referenceMessages(Output $output): array
    {
        return array_values(array_filter($this->messages($output), static fn (string $message): bool => strpos($message, 'is not supported and has no effect') === false));
    }

    /**
     * @return list<array{string, int|null, bool}>
     */
    private function summary(Output $output): array
    {
        return array_map(
            static fn (ResolvedSchema $schema): array => [$schema->name(), $schema->source(), $schema->isSelected()],
            $output->graph()->all(),
        );
    }

    public function testWritesTheConfiguredNamesIntoTheSchemasAsTheyAreRead(): void
    {
        $fields = ['properties' => ['issuetype' => ['type' => 'string'], 'issueType' => ['$ref' => '../shared/common.json#/Type']]];
        $output = $this->load(
            [
                self::SPEC => $this->spec(['field' => ['type' => 'object'], 'fields' => ['type' => 'object', 'x-php-class-name' => 'Mine'], 'Fields' => $fields, 'Both' => ['allOf' => [['$ref' => '#/components/schemas/Fields'], ['properties' => ['a' => ['type' => 'object']]]]]]),
                self::SHARED => ['Type' => ['type' => 'object']],
            ],
            $this->named(
                ['*'],
                [NameOverride::CLASS_NAME, '#/components/schemas/fields', 'FieldList'],
                [NameOverride::CLASS_NAME, '#/components/schemas/Both/allOf/1/properties/a', 'BothA'],
                [NameOverride::CLASS_NAME, '../shared/common.json#/Type', 'IssueType'],
                [NameOverride::PROPERTY_NAME, '#/components/schemas/Fields/properties/issueType', 'issueTypeObject'],
            ),
        );

        $schemas = [];
        foreach ($output->graph()->all() as $schema) {
            $schemas[$schema->name()] = $schema->schema();
        }

        self::assertSame([], $this->messages($output));
        self::assertSame('FieldList', $schemas['fields']->extensions()->get('x-php-class-name'));
        self::assertFalse($schemas['field']->extensions()->has('x-php-class-name'));
        self::assertSame('issueTypeObject', $this->property($schemas['Fields'], 'issueType')->extensions()->get('x-php-name'));
        self::assertFalse($this->property($schemas['Fields'], 'issuetype')->extensions()->has('x-php-name'));
        self::assertSame('BothA', $this->property($schemas['Both']->allOf()[1], 'a')->extensions()->get('x-php-class-name'));
        self::assertSame('IssueType', $schemas['Type']->extensions()->get('x-php-class-name'));
    }

    public function testWarnsOfANameGivenToNoSchemaItReads(): void
    {
        $tag = [
            'type' => 'object', 'default' => ['k' => 1], 'const' => ['k' => 1], 'allOf' => [['type' => 'object']], 'properties' => ['p' => ['type' => 'object'], 'q' => []],
            'items' => [['type' => 'object']], 'not' => ['type' => 'object'], 'additionalProperties' => false,
        ];
        $output = $this->load(
            [self::SPEC => $this->spec(['Tag' => $tag, 'Skipped' => ['type' => 'object']])],
            $this->named(
                ['Tag'],
                [NameOverride::CLASS_NAME, '#/components/schemas/Pet', 'A'],
                [NameOverride::CLASS_NAME, '#/components/schemas/Tag/type/x', 'B'],
                [NameOverride::CLASS_NAME, '#/components/schemas/Tag/allOf', 'C'],
                [NameOverride::CLASS_NAME, '#/components/schemas/Skipped', 'D'],
                [NameOverride::CLASS_NAME, '../shared/common.json', 'E'],
                [NameOverride::CLASS_NAME, '#/components/schemas/Tag/default', 'F'],
                [NameOverride::CLASS_NAME, '#/components/schemas/Tag/const', 'G'],
                [NameOverride::CLASS_NAME, '#/components/schemas/Tag/properties', 'H'],
                [NameOverride::CLASS_NAME, '#/components/schemas/Tag/allOf/0', 'I'],
                [NameOverride::CLASS_NAME, '#/components/schemas/Tag/items/0', 'J'],
                [NameOverride::CLASS_NAME, '#/components/schemas/Tag/not', 'K'],
                [NameOverride::CLASS_NAME, '#/components/schemas/Tag/items', 'L'],
                [NameOverride::CLASS_NAME, '#/components/schemas/Tag/additionalProperties', 'M'],
                [NameOverride::CLASS_NAME, '#/components/schemas/Tag/properties/q', 'N'],
            ),
        );

        $at = 'warning /project/dto-generator.yaml#/n/';
        self::assertSame(
            [
                $at . '0: No schema read is at /project/api/openapi.yaml#/components/schemas/Pet; the name is not used.',
                $at . '1: No schema read is at /project/api/openapi.yaml#/components/schemas/Tag/type/x; the name is not used.',
                $at . '2: No schema read is at /project/api/openapi.yaml#/components/schemas/Tag/allOf; the name is not used.',
                $at . '3: No schema read is at /project/api/openapi.yaml#/components/schemas/Skipped; the name is not used.',
                $at . '4: No schema read is at /project/shared/common.json#; the name is not used. To name a schema inside the file, add its JSON pointer ("common.json#/Type").',
                $at . '5: No schema read is at /project/api/openapi.yaml#/components/schemas/Tag/default; the name is not used.',
                $at . '6: No schema read is at /project/api/openapi.yaml#/components/schemas/Tag/const; the name is not used.',
                $at . '7: No schema read is at /project/api/openapi.yaml#/components/schemas/Tag/properties; the name is not used.',
                $at . '11: No schema read is at /project/api/openapi.yaml#/components/schemas/Tag/items; the name is not used.',
                $at . '12: No schema read is at /project/api/openapi.yaml#/components/schemas/Tag/additionalProperties; the name is not used.',
            ],
            array_values(array_filter($this->messages($output), static fn (string $message): bool => strpos($message, $at) === 0)),
        );
        $schema = $output->graph()->all()[0]->schema();
        $default = $schema->default();
        self::assertNotNull($default);
        self::assertSame(['k' => 1], $default->value());
        self::assertSame(['p', 'q'], $schema->propertyNames());
        $q = $schema->property('q');
        self::assertNotNull($q);
        self::assertSame('N', $q->extensions()->get('x-php-class-name'));
    }

    public function testNamesASchemaThatIsAWholeFile(): void
    {
        $output = $this->load(
            [self::SPEC => $this->spec(['User' => ['properties' => ['salary' => ['$ref' => '../shared/common.json']]]]), self::SHARED => ['type' => 'object']],
            $this->named(['*'], [NameOverride::CLASS_NAME, '../shared/common.json', 'Salary']),
        );

        self::assertSame([], $this->messages($output));
        self::assertSame('Salary', $output->graph()->all()[1]->schema()->extensions()->get('x-php-class-name'));
    }

    public function testTakesTheNamesOfEverySourceAndRejectsTwoForOneSchema(): void
    {
        $output = $this->load(
            [
                self::SPEC => $this->spec(['User' => ['type' => 'object'], 'Money' => ['type' => 'object']]),
                self::OTHER => $this->spec(['Pet' => ['properties' => ['price' => ['$ref' => '../api/openapi.yaml#/components/schemas/Money']]]]),
            ],
            $this->namedAt(self::SPEC, '/a/', ['*'], [NameOverride::CLASS_NAME, '#/components/schemas/User', 'Person'], [NameOverride::CLASS_NAME, '#/components/schemas/Money', 'Cash'], [NameOverride::PROPERTY_NAME, '#/components/schemas/Money', 'cash']),
            $this->namedAt(self::OTHER, '/b/', ['*'], [NameOverride::CLASS_NAME, '#/components/schemas/Pet', 'Animal'], [NameOverride::CLASS_NAME, '../api/openapi.yaml#/components/schemas/Money', 'Cash'], [NameOverride::CLASS_NAME, '../api/openapi.yaml#/components/schemas/Money', 'Money']),
        );

        self::assertSame(
            ['error /project/dto-generator.yaml#/b/2: /project/api/openapi.yaml#/components/schemas/Money is named "Cash" already, at /project/dto-generator.yaml#/a/1.'],
            array_values(array_filter($this->messages($output), static fn (string $message): bool => strpos($message, 'error') === 0)),
        );
        $names = [];
        foreach ($output->graph()->all() as $schema) {
            $names[$schema->name()] = $schema->schema()->extensions()->get('x-php-class-name');
        }

        self::assertSame(['User' => 'Person', 'Money' => 'Cash', 'Pet' => 'Animal'], $names);
    }

    public function testLeavesTheNamesOfASourceItCannotReadUnreported(): void
    {
        $output = $this->load([], $this->named(['*'], [NameOverride::CLASS_NAME, '#/components/schemas/Pet', 'A']));

        self::assertCount(1, $this->messages($output));
        self::assertStringStartsWith('error /project/dto-generator.yaml', $this->messages($output)[0]);
    }

    /**
     * @param list<string> $include
     * @param array{NameOverride::CLASS_NAME|NameOverride::PROPERTY_NAME, string, string} ...$names keyword, reference and name
     */
    private function named(array $include, array ...$names): SourceConfig
    {
        return $this->namedAt(self::SPEC, '/n/', $include, ...$names);
    }

    /**
     * @param list<string> $include
     * @param array{NameOverride::CLASS_NAME|NameOverride::PROPERTY_NAME, string, string} ...$names keyword, reference and name
     */
    private function namedAt(string $spec, string $at, array $include, array ...$names): SourceConfig
    {
        $overrides = [];
        foreach ($names as $index => [$keyword, $ref, $name]) {
            $overrides[] = new NameOverride($keyword, Reference::target($ref, new SchemaLocation($spec)), $name, new SchemaLocation('/project/dto-generator.yaml', $at . $index));
        }

        return new SourceConfig($spec, 'App\\Dto' . strlen($at), '/project/src/Dto' . count($names), $include, [], $overrides);
    }

    private function property(Schema $schema, string $name): Schema
    {
        $property = $schema->property($name);
        self::assertInstanceOf(Schema::class, $property);

        return $property;
    }

    public function testKeepsLoadingAfterABrokenOrDuplicateSource(): void
    {
        $output = $this->load(
            [self::SPEC => self::spec(['User' => []]), self::OTHER => self::spec(['Pet' => []])],
            ConfigMother::source('/project/missing.yaml'),
            ConfigMother::source(self::SPEC, ['*'], [], 'App\\A'),
            ConfigMother::source(self::SPEC, ['*'], [], 'App\\B'),
            ConfigMother::source(self::OTHER, ['*'], [], 'App\\C'),
        );

        self::assertCount(2, $output->diagnostics()->errors());
        self::assertSame([['User', 1, true], ['Pet', 3, true]], $this->summary($output));
    }

    public function testKeepsFollowingReferencesAfterABrokenOne(): void
    {
        $output = $this->load(
            [self::SPEC => self::spec([
                'User' => ['properties' => [
                    'a' => ['$ref' => 'https://example.com/x.json'],
                    'b' => ['$ref' => '#/components/schemas/Nope'],
                    'c' => ['$ref' => '#/components/schemas/Nope'],
                    'd' => ['$ref' => '#/components/schemas/Tag'],
                ]],
                'Tag' => [],
            ])],
            ConfigMother::source(self::SPEC, ['User']),
        );

        self::assertCount(2, $output->diagnostics()->errors(), 'a missing target is reported once');
        self::assertSame([['User', 0, true], ['Tag', 0, false]], $this->summary($output));
    }

    public function testDetectsAmbiguityReachedThroughAnotherForeignSchema(): void
    {
        $output = $this->load(
            [
                self::SPEC => self::spec(['User' => ['$ref' => '../shared/common.json#/A']]),
                self::OTHER => self::spec(['Pet' => ['$ref' => '../shared/common.json#/F']]),
                self::SHARED => ['A' => [], 'F' => ['$ref' => '#/A']],
            ],
            ConfigMother::source(self::SPEC),
            ConfigMother::source(self::OTHER, ['*'], [], 'App\\Other'),
        );

        self::assertSame(
            ['error /project/shared/common.json#/A: Schema is referenced from sources #0, #1, so its namespace is ambiguous; add its file as a source.'],
            $this->messages($output),
        );
    }

    public function testKeepsNumericComponentNamesAsStrings(): void
    {
        $output = $this->load([self::SPEC => self::spec(['200' => ['type' => 'object']])]);

        self::assertSame([['200', 0, true]], $this->summary($output));
    }

    public function testWarnsAboutAVersionThatOnlyEndsLikeThreeOne(): void
    {
        $output = $this->load([self::SPEC => ['openapi' => '13.1', 'components' => ['schemas' => []]]]);

        self::assertCount(1, $output->diagnostics());
        self::assertStringContainsString('found "13.1"', $output->diagnostics()->all()[0]->message());
    }

    public function testReportsAnEmptyComponentNameAndKeepsTheRest(): void
    {
        $output = $this->load([self::SPEC => self::spec(['' => ['type' => 'object'], 'User' => []])]);

        self::assertSame(
            ['error /project/api/openapi.yaml#/components/schemas/: A schema name in components/schemas must not be empty.'],
            $this->messages($output),
        );
        self::assertSame([['User', 0, true]], $this->summary($output));
    }

    /**
     * @dataProvider unquotedOpenApiVersions
     */
    public function testUnderstandsUnquotedYamlVersions(float $version, int $warnings): void
    {
        $output = $this->load([self::SPEC => ['openapi' => $version, 'components' => ['schemas' => []]]]);

        self::assertCount($warnings, $output->diagnostics());
    }

    /**
     * @return array<string, array{float, int}>
     */
    public static function unquotedOpenApiVersions(): array
    {
        return [
            '3.1' => [3.1, 0],
            '3.0' => [3.0, 0],
            '3.2' => [3.2, 0],
            '3.3' => [3.3, 1],
        ];
    }

    public function testRecordsHowEveryReferenceResolved(): void
    {
        $output = $this->load([self::SPEC => self::spec([
            'User' => ['properties' => ['tag' => ['$ref' => '#/components/schemas/Tag'], 'bad' => ['$ref' => '#Anchor']]],
            'Tag' => [],
        ])]);
        $user = $output->graph()->all()[0];
        [$tag, $bad] = $user->schema()->references();

        $resolved = $output->graph()->resolve($tag);
        self::assertNotNull($resolved);
        self::assertSame('Tag', $resolved->name());
        self::assertNull($output->graph()->resolve($bad));
    }

    public function testFollowsTheReferencesOfKeywordsWithoutGivingTheirTargetsASource(): void
    {
        $output = $this->load(
            [
                self::SPEC => $this->spec([
                    'User' => [
                        'propertyNames' => ['$ref' => '#/components/schemas/Name'],
                        'properties' => ['tags' => ['contains' => ['$ref' => '../shared/common.json#/Tag']]],
                        'not' => ['$ref' => '#/components/schemas/User/properties/tags'],
                    ],
                    'Name' => ['type' => 'string'],
                    'Hidden' => ['type' => 'string'],
                ]),
                self::SHARED => [
                    'Tag' => ['allOf' => [['$ref' => '#/Code']], 'not' => ['$ref' => '#/Banned']],
                    'Code' => ['type' => 'string'],
                    'Banned' => ['const' => 'x'],
                ],
            ],
            ConfigMother::source(self::SPEC, ['User', 'Name']),
        );

        self::assertSame([], $this->referenceMessages($output));
        self::assertSame([['User', 0, true], ['Name', 0, true], ['tags', null, false], ['Tag', null, false], ['Code', null, false], ['Banned', null, false]], $this->summary($output));
        $user = $output->graph()->all()[0]->schema();
        $resolved = array_map(function (ReferenceUse $use) use ($output): string {
            $target = $output->graph()->resolve($use);
            self::assertNotNull($target);

            return $target->location()->toString();
        }, $user->keywordReferences());
        self::assertSame([
            '/project/api/openapi.yaml#/components/schemas/User/properties/tags',
            '/project/api/openapi.yaml#/components/schemas/Name',
            '/project/shared/common.json#/Tag',
        ], $resolved);
    }

    public function testAKeywordReferenceLeavesTheSourceOfASchemaOtherwiseReferenced(): void
    {
        $output = $this->load([
            self::SPEC => $this->spec(['User' => [
                'not' => ['$ref' => '../shared/common.json#/Money'],
                'properties' => ['salary' => ['$ref' => '../shared/common.json#/Money']],
            ]]),
            self::SHARED => ['Money' => ['type' => 'object']],
        ]);

        self::assertSame([['User', 0, true], ['Money', 0, false]], $this->summary($output));
    }

    public function testWarnsAboutAKeywordReferenceThatCannotBeFollowed(): void
    {
        $output = $this->load(
            [self::SPEC => $this->spec(['User' => [
                'if' => ['$ref' => 'https://example.com/a.json'],
                'not' => ['$ref' => '#/components/schemas/Nope'],
                'contains' => ['$ref' => '../missing.json#/A'],
                'then' => ['$ref' => '#/components/schemas/Tag'],
                'else' => ['$ref' => '#/components/schemas/Nope'],
                'properties' => ['tag' => ['$ref' => '#/components/schemas/Tag']],
            ]])],
        );

        self::assertSame([
            'error /project/api/openapi.yaml#/components/schemas/User/properties/tag: $ref "#/components/schemas/Tag" does not resolve: /project/api/openapi.yaml has nothing at "/components/schemas/Tag".',
            'warning /project/api/openapi.yaml#/components/schemas/User/not: $ref "#/components/schemas/Nope" does not resolve: /project/api/openapi.yaml has nothing at "/components/schemas/Nope".',
            'warning /project/api/openapi.yaml#/components/schemas/User/if: Remote $ref "https://example.com/a.json" names https://example.com/a.json, which remoteRefs.allow does not cover; add its prefix there, or save the document next to the specification and refer to it by path.',
            'warning /project/api/openapi.yaml#/components/schemas/User/contains: File "/project/missing.json" does not exist.',
        ], $this->referenceMessages($output));
        self::assertSame([['User', 0, true]], $this->summary($output));
    }

    public function testLeavesOutAKeywordTargetThatIsNoSchemaItCanRead(): void
    {
        $output = $this->load([
            self::SPEC => $this->spec(['User' => [
                'not' => ['$ref' => '../shared/common.json#/Never'],
                'if' => ['$ref' => '../shared/common.json#/Text'],
                'then' => ['$ref' => '../shared/common.json#/Odd'],
            ]]),
            self::SHARED => ['Never' => false, 'Text' => 'oops', 'Odd' => ['type' => 'string', 'not' => ['const' => 'x'], 'x-php-nmae' => 'y']],
        ]);

        self::assertSame([
            'warning /project/shared/common.json#/Never: The "false" schema is not supported.',
            'warning /project/shared/common.json#/Text: A schema must be an object.',
        ], $this->referenceMessages($output));
        self::assertSame([['User', 0, true], ['Odd', null, false]], $this->summary($output));
        self::assertSame([], array_values(array_filter($this->messages($output), static fn (string $message): bool => strpos($message, '#/Odd') !== false)), 'the extension reads what it can');
    }

    public function testRejectsComponentsThatAreNotAnObject(): void
    {
        $output = $this->load([self::SPEC => ['openapi' => '3.1.0', 'components' => 'none']]);

        self::assertSame(['error /project/api/openapi.yaml#/components: "components" must be an object.'], $this->messages($output));
    }

    public function testReportsAnUnloadableFileOnce(): void
    {
        $output = $this->load(
            [
                self::SPEC => self::spec(['User' => ['properties' => [
                    'a' => ['$ref' => '../other/openapi.yaml#/components/schemas/A'],
                    'b' => ['$ref' => '../other/openapi.yaml#/components/schemas/B'],
                ]]]),
            ],
            ConfigMother::source(self::SPEC),
            ConfigMother::source(self::OTHER, ['*'], [], 'App\\Other'),
        );

        self::assertSame(['error /project/dto-generator.yaml#/sources/1/spec: File "/project/other/openapi.yaml" does not exist.'], $this->messages($output));
    }

    public function testListsAmbiguousOwnersInOrder(): void
    {
        $output = $this->load(
            [
                self::SPEC => self::spec(['User' => ['$ref' => '../shared/common.json#/F']]),
                self::OTHER => self::spec(['Pet' => ['$ref' => '../shared/common.json#/A']]),
                self::SHARED => ['A' => [], 'F' => ['$ref' => '#/A']],
            ],
            ConfigMother::source(self::SPEC),
            ConfigMother::source(self::OTHER, ['*'], [], 'App\\Other'),
        );

        self::assertSame(
            ['error /project/shared/common.json#/A: Schema is referenced from sources #0, #1, so its namespace is ambiguous; add its file as a source.'],
            $this->messages($output),
        );
        self::assertNull($output->graph()->all()[3]->source());
    }

    public function testFollowsCyclesAcrossForeignFiles(): void
    {
        $output = $this->load([
            self::SPEC => self::spec(['User' => ['$ref' => '../shared/common.json#/A']]),
            self::SHARED => ['A' => ['properties' => ['b' => ['$ref' => 'nested/b.json#/B'], 'self' => ['$ref' => '#/A']]]],
            '/project/shared/nested/b.json' => ['B' => ['properties' => ['a' => ['$ref' => '../common.json#/A']]]],
        ]);

        self::assertSame([], $this->messages($output));
        self::assertSame([['User', 0, true], ['A', 0, false], ['B', 0, false]], $this->summary($output));
    }

    public function testDifferentSpellingsReachOneSchema(): void
    {
        $output = $this->load([self::SPEC => self::spec([
            'User' => ['properties' => [
                'a' => ['$ref' => 'openapi.yaml#/components/schemas/Tag'],
                'b' => ['$ref' => '../api/./openapi.yaml#/components/schemas/Tag'],
                'c' => ['$ref' => '#/components/schemas/T%61g'],
                'd' => ['$ref' => '#/components/schemas/a%7E1b'],
            ]],
            'Tag' => [],
            'a/b' => [],
        ])], ConfigMother::source(self::SPEC, ['User']));

        self::assertSame([], $this->messages($output));
        self::assertSame([['User', 0, true], ['Tag', 0, false], ['a/b', 0, false]], $this->summary($output));
    }

    public function testReportsABadEscapeInsteadOfThrowing(): void
    {
        $output = $this->load([self::SPEC => self::spec(['User' => ['$ref' => '#/components/schemas/a~2b']])]);

        self::assertSame(['error /project/api/openapi.yaml#/components/schemas/User: "/components/schemas/a~2b" is not a valid JSON pointer.'], $this->messages($output));
    }

    public function testReportsATargetThatIsNotASchema(): void
    {
        $output = $this->load([self::SPEC => self::spec(['User' => ['$ref' => '#/openapi']])]);

        self::assertSame(['error /project/api/openapi.yaml#/openapi: A schema must be an object.'], $this->messages($output));
    }

    public function testReportsAMalformedReferencedFile(): void
    {
        $output = $this->load([
            self::SPEC => self::spec(['User' => ['$ref' => '../shared/common.json#/A']]),
            self::SHARED => DocumentLoadFailed::malformed(self::SHARED, 'Syntax error'),
        ]);

        self::assertSame(['error /project/api/openapi.yaml#/components/schemas/User: File "/project/shared/common.json" is not valid: Syntax error'], $this->messages($output));
    }

    public function testAnUnselectedComponentKeepsItsSource(): void
    {
        $output = $this->load(
            [
                self::SPEC => self::spec(['User' => ['$ref' => '../other/openapi.yaml#/components/schemas/Pet']]),
                self::OTHER => self::spec(['Pet' => [], 'Owner' => []]),
            ],
            ConfigMother::source(self::SPEC),
            ConfigMother::source(self::OTHER, ['Owner'], [], 'App\\Other'),
        );

        self::assertSame([['User', 0, true], ['Owner', 1, true], ['Pet', 1, false]], $this->summary($output));
    }

    public function testAcceptsAnEmptyComponentsObject(): void
    {
        $output = $this->load([self::SPEC => ['openapi' => '3.1.0', 'components' => []]]);

        self::assertSame(['warning /project/api/openapi.yaml#/components/schemas: The specification has no components/schemas; nothing to generate.'], $this->messages($output));
    }

    public function testRejectsComponentsGivenAsAList(): void
    {
        $output = $this->load([self::SPEC => ['openapi' => '3.1.0', 'components' => ['schemas']]]);

        self::assertSame(['error /project/api/openapi.yaml#/components: "components" must be an object.'], $this->messages($output));
    }

    public function testHandlesASpecificationInTheFilesystemRoot(): void
    {
        $output = $this->load(
            ['/spec.yaml' => self::spec(['User' => ['$ref' => 'spec.yaml#/components/schemas/Tag'], 'Tag' => []])],
            ConfigMother::source('/spec.yaml', ['User']),
        );

        self::assertSame([], $this->messages($output));
        self::assertSame([['User', 0, true], ['Tag', 0, false]], $this->summary($output));
        self::assertSame('/spec.yaml#/components/schemas/Tag', $output->graph()->all()[1]->location()->toString());
    }
}
