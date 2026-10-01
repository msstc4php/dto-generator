<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Application\Service\Schemas;

use MSSTC4PHP\DtoGenerator\Application\Config\SourceConfig;
use MSSTC4PHP\DtoGenerator\Application\Service\Schemas\Load\Action;
use MSSTC4PHP\DtoGenerator\Application\Service\Schemas\Load\Input;
use MSSTC4PHP\DtoGenerator\Application\Service\Schemas\Load\Output;
use MSSTC4PHP\DtoGenerator\Domain\Builder\SchemaParser;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostic;
use MSSTC4PHP\DtoGenerator\Domain\Schema\ResolvedSchema;
use MSSTC4PHP\DtoGenerator\Tests\Support\ConfigMother;
use MSSTC4PHP\DtoGenerator\Tests\Support\InMemoryDocumentLoader;
use PHPUnit\Framework\TestCase;

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
                $at . 'Remote $ref "https://example.com/x.json" is not supported; save the document next to the specification and refer to it by path.',
            ],
            'missing pointer' => [
                ['properties' => ['x' => ['$ref' => '#/components/schemas/Nope']]],
                $at . '$ref "#/components/schemas/Nope" does not resolve: /project/api/openapi.yaml has nothing at "/components/schemas/Nope".',
            ],
            'missing file' => [
                ['properties' => ['x' => ['$ref' => 'missing.yaml#/X']]],
                $at . 'File "/project/api/missing.yaml" does not exist.',
            ],
            'anchor' => [
                ['properties' => ['x' => ['$ref' => '#User']]],
                $at . '$ref "#User": only JSON pointer fragments are supported, not anchors.',
            ],
        ];
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

    public function testWarnsAboutOtherOpenApiVersions(): void
    {
        $output = $this->load([self::SPEC => ['openapi' => '3.0.3', 'components' => ['schemas' => []]]]);

        self::assertSame(
            ['warning /project/api/openapi.yaml#/openapi: Expected OpenAPI 3.1, found "3.0.3"; keywords specific to that version (such as "nullable") are not understood.'],
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
        $output = $this->load([self::SPEC => ['openapi' => '3.1.0', 'components' => ['schemas' => [['type' => 'object']]]]]);

        self::assertSame(['error /project/api/openapi.yaml#/components/schemas: "schemas" must be an object.'], $this->messages($output));
    }

    /**
     * @param array<string, array<array-key, mixed>> $documents
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
     * @return list<array{string, int|null, bool}>
     */
    private function summary(Output $output): array
    {
        return array_map(
            static fn (ResolvedSchema $schema): array => [$schema->name(), $schema->source(), $schema->isSelected()],
            $output->graph()->all(),
        );
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
}
