<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Application\Service\Schemas;

use MSSTC4PHP\DtoGenerator\Application\Config\OperationFilter;
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

final class LoadOperationsTest extends TestCase
{
    private const SPEC = '/project/api/openapi.yaml';

    private const AT = self::SPEC . '#/paths/~1pets~1{id}/';

    private const OBJECT = ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]];

    public function testReadsTheSchemasOfRequestBodiesAndResponses(): void
    {
        $output = $this->load(['paths' => ['/pets/{id}' => [
            'parameters' => [],
            'put' => [
                'operationId' => 'updatePet',
                'requestBody' => $this->json(self::OBJECT),
                'responses' => ['200' => $this->json(self::OBJECT), 'default' => $this->json(self::OBJECT), '204' => ['description' => 'Done.']],
            ],
            'get' => ['responses' => ['2XX' => $this->json(self::OBJECT)]],
            'x-other' => ['responses' => ['200' => $this->json(self::OBJECT)]],
        ]]]);

        self::assertSame([], $this->messages($output));
        self::assertSame([
            ['get /pets/{id} 2XX Response', self::AT . 'get/responses/2XX/content/application~1json/schema'],
            ['updatePet Request', self::AT . 'put/requestBody/content/application~1json/schema'],
            ['updatePet 200 Response', self::AT . 'put/responses/200/content/application~1json/schema'],
            ['updatePet default Response', self::AT . 'put/responses/default/content/application~1json/schema'],
        ], $this->summary($output));
    }

    public function testReadsTheRequestBodiesAndResponsesOfComponentsTheOperationsName(): void
    {
        $output = $this->load([
            'paths' => ['/pets/{id}' => [
                'post' => ['operationId' => 'a', 'requestBody' => ['$ref' => '#/components/requestBodies/NewPet'], 'responses' => ['404' => ['$ref' => 'openapi.yaml#/components/responses/NotFound']]],
                'put' => ['operationId' => 'b', 'requestBody' => ['$ref' => '#/components/requestBodies/NewPet'], 'responses' => ['404' => ['$ref' => '#/components/responses/NotFound'], '410' => ['$ref' => '#/components/responses/Gone']]],
            ]],
            'components' => [
                'schemas' => ['Pet' => self::OBJECT],
                'requestBodies' => ['NewPet' => $this->json(self::OBJECT), 'Unused' => $this->json(self::OBJECT)],
                'responses' => ['NotFound' => $this->json(self::OBJECT), 'Gone' => ['$ref' => '#/components/responses/Loop'], 'Loop' => ['$ref' => '#/components/responses/Loop']],
            ],
        ]);

        self::assertSame([], $this->messages($output));
        self::assertSame([
            ['Pet', self::SPEC . '#/components/schemas/Pet'],
            ['NewPet Request', self::SPEC . '#/components/requestBodies/NewPet/content/application~1json/schema'],
            ['NotFound Response', self::SPEC . '#/components/responses/NotFound/content/application~1json/schema'],
        ], $this->summary($output));
        self::assertSame([false, true, true], array_map(static fn (ResolvedSchema $schema): bool => $schema->isNamedByPlace(), $output->graph()->all()));
        self::assertSame([true, true, true], array_map(static fn (ResolvedSchema $schema): bool => $schema->isSelected(), $output->graph()->all()));
        self::assertSame([0, 0, 0], array_map(static fn (ResolvedSchema $schema): ?int => $schema->source(), $output->graph()->all()));
    }

    public function testPrefersTheFirstJsonMediaTypeAndElseTakesTheFirstWithASchema(): void
    {
        $content = static fn (array $media): array => ['content' => $media];
        $output = $this->load(['paths' => ['/pets/{id}' => ['post' => [
            'operationId' => 'a',
            'requestBody' => $content(['application/xml' => ['schema' => self::OBJECT], 'application/problem+json ; charset=utf-8' => ['schema' => self::OBJECT]]),
            'responses' => [
                '200' => $content(['text/plain' => [], 'text/csv' => ['schema' => self::OBJECT], 'application/xml' => ['schema' => self::OBJECT]]),
                '201' => $content(['text/csv' => ['schema' => self::OBJECT], 'APPLICATION/JSON' => ['schema' => self::OBJECT], 'application/json' => ['schema' => self::OBJECT]]),
                '202' => $content(['text/plain' => []]),
                '203' => $content(['1' => ['schema' => self::OBJECT]]),
            ],
        ]]]]);

        self::assertSame([
            ['a Request', self::AT . 'post/requestBody/content/application~1problem+json ; charset=utf-8/schema'],
            ['a 200 Response', self::AT . 'post/responses/200/content/text~1csv/schema'],
            ['a 201 Response', self::AT . 'post/responses/201/content/APPLICATION~1JSON/schema'],
            ['a 203 Response', self::AT . 'post/responses/203/content/1/schema'],
        ], $this->summary($output));
    }

    public function testReadsTheQueryMethodAndAdditionalOperations(): void
    {
        $output = $this->load(['paths' => ['/pets/{id}' => [
            'query' => ['operationId' => 'searchPets', 'requestBody' => $this->json(self::OBJECT)],
            'additionalOperations' => [
                'LINK' => ['responses' => ['200' => $this->json(self::OBJECT)]],
                'COPY' => ['operationId' => 'copyPet', 'responses' => ['201' => $this->json(self::OBJECT)]],
                'POST' => ['operationId' => 'other', 'responses' => ['200' => $this->json(self::OBJECT)]],
                'query' => ['operationId' => 'again', 'responses' => ['200' => $this->json(self::OBJECT)]],
                'PURGE' => 'not an operation',
                '1' => ['responses' => ['200' => $this->json(self::OBJECT)]],
            ],
        ]]]);

        self::assertSame([
            'warning ' . self::AT . 'additionalOperations/POST: "POST" has its own field in a path item; it is not read here.',
            'warning ' . self::AT . 'additionalOperations/query: "query" has its own field in a path item; it is not read here.',
        ], $this->messages($output));
        self::assertSame([
            ['searchPets Request', self::AT . 'query/requestBody/content/application~1json/schema'],
            ['link /pets/{id} 200 Response', self::AT . 'additionalOperations/LINK/responses/200/content/application~1json/schema'],
            ['copyPet 201 Response', self::AT . 'additionalOperations/COPY/responses/201/content/application~1json/schema'],
            ['1 /pets/{id} 200 Response', self::AT . 'additionalOperations/1/responses/200/content/application~1json/schema'],
        ], $this->summary($output));
    }

    public function testFiltersAdditionalOperationsByTheirMethodInLowerCase(): void
    {
        $output = $this->load(
            ['paths' => ['/pets/{id}' => ['additionalOperations' => [
                'LINK' => ['responses' => ['200' => $this->json(self::OBJECT)]],
                'UNLINK' => ['responses' => ['200' => $this->json(self::OBJECT)]],
            ]]]],
            new OperationFilter(['link *'], []),
        );

        self::assertSame(['link /pets/{id} 200 Response'], array_column($this->summary($output), 0));
    }

    public function testReadsTheItemSchemaOfASequentialMediaType(): void
    {
        $content = static fn (array $media): array => ['content' => $media];
        $output = $this->load(['paths' => ['/pets/{id}' => ['get' => [
            'operationId' => 'a',
            'responses' => [
                '200' => $content(['text/event-stream' => ['itemSchema' => self::OBJECT], 'application/jsonl' => ['itemSchema' => self::OBJECT]]),
                '201' => $content(['text/event-stream' => ['itemSchema' => self::OBJECT], 'application/x-ndjson' => ['itemSchema' => self::OBJECT]]),
                '202' => $content(['text/event-stream' => ['itemSchema' => self::OBJECT], 'application/json-seq' => ['itemSchema' => self::OBJECT]]),
                '203' => $content(['text/event-stream' => ['itemSchema' => self::OBJECT], 'application/geo+json-seq' => ['itemSchema' => self::OBJECT]]),
                '207' => $content(['text/event-stream' => ['itemSchema' => self::OBJECT], 'Application/JSONL; charset=utf-8' => ['itemSchema' => self::OBJECT]]),
                '208' => $content(['text/event-stream' => ['itemSchema' => self::OBJECT], 'application/json-seq-x' => ['itemSchema' => self::OBJECT]]),
                '204' => $content(['text/event-stream' => ['itemSchema' => self::OBJECT]]),
                '205' => $content(['application/jsonl' => ['schema' => self::OBJECT, 'itemSchema' => self::OBJECT]]),
                '206' => $content(['application/jsonl' => ['itemSchema' => ['$ref' => '#/components/schemas/Pet']]]),
            ],
        ]]], 'components' => ['schemas' => ['Pet' => self::OBJECT]]]);

        self::assertSame([], $this->messages($output));
        self::assertSame([
            ['Pet', self::SPEC . '#/components/schemas/Pet'],
            ['a 200 Response Item', self::AT . 'get/responses/200/content/application~1jsonl/itemSchema'],
            ['a 201 Response Item', self::AT . 'get/responses/201/content/application~1x-ndjson/itemSchema'],
            ['a 202 Response Item', self::AT . 'get/responses/202/content/application~1json-seq/itemSchema'],
            ['a 203 Response Item', self::AT . 'get/responses/203/content/application~1geo+json-seq/itemSchema'],
            ['a 207 Response Item', self::AT . 'get/responses/207/content/Application~1JSONL; charset=utf-8/itemSchema'],
            ['a 208 Response Item', self::AT . 'get/responses/208/content/text~1event-stream/itemSchema'],
            ['a 204 Response Item', self::AT . 'get/responses/204/content/text~1event-stream/itemSchema'],
            ['a 205 Response', self::AT . 'get/responses/205/content/application~1jsonl/schema'],
        ], $this->summary($output));
    }

    public function testReadsTheMediaTypesOfComponentsTheContentNames(): void
    {
        $output = $this->load([
            'paths' => ['/pets/{id}' => [
                'get' => ['operationId' => 'a', 'responses' => [
                    '200' => ['content' => ['application/jsonl' => ['$ref' => '#/components/mediaTypes/Log']]],
                    '201' => ['content' => ['text/plain' => ['schema' => self::OBJECT], 'application/json' => ['$ref' => '#/components/mediaTypes/Pet']]],
                    '202' => ['content' => ['application/json' => ['$ref' => '#/components/mediaTypes/Alias']]],
                    '204' => ['$ref' => '#/components/responses/Events'],
                    '203' => ['content' => ['application/json' => ['$ref' => '#/components/mediaTypes/Pet', 'schema' => self::OBJECT]]],
                ]],
                'put' => ['operationId' => 'b', 'requestBody' => ['content' => ['application/json' => ['$ref' => '#/components/mediaTypes/Pet']]]],
            ]],
            'components' => [
                'mediaTypes' => [
                    'Log' => ['itemSchema' => self::OBJECT],
                    'Pet' => ['schema' => self::OBJECT],
                    'Alias' => ['$ref' => '#/components/mediaTypes/Pet'],
                    'Event' => ['schema' => self::OBJECT],
                ],
                'responses' => ['Events' => ['content' => ['application/json' => ['$ref' => '#/components/mediaTypes/Event']]]],
            ],
        ]);

        self::assertSame([], $this->messages($output));
        self::assertSame([
            ['Log Media Type Item', self::SPEC . '#/components/mediaTypes/Log/itemSchema'],
            ['Pet Media Type', self::SPEC . '#/components/mediaTypes/Pet/schema'],
            ['Event Media Type', self::SPEC . '#/components/mediaTypes/Event/schema'],
        ], $this->summary($output));
    }

    public function testReadsAMediaTypeBehindARefOnlyInTheComponents(): void
    {
        $content = static fn (string $ref): array => ['content' => ['application/json' => ['$ref' => $ref]]];
        $output = $this->load([
            'paths' => ['/pets/{id}' => ['get' => ['operationId' => 'a', 'responses' => [
                '200' => $content('#/components/schemas/Pet'),
                '201' => $content('#/components/mediaTypes/Missing'),
                '202' => $content('#/components/responses/Pet'),
                '203' => $content('#/components/mediaTypes/List'),
                '205' => ['content' => ['application/json' => ['$ref' => '#/components/mediaTypes/Missing'], 'text/csv' => ['schema' => self::OBJECT]]],
            ]]]],
            'components' => ['schemas' => ['Pet' => self::OBJECT], 'responses' => ['Pet' => $this->json(self::OBJECT)], 'mediaTypes' => ['List' => [['schema' => self::OBJECT]]]],
        ]);

        self::assertSame([
            'warning ' . self::AT . 'get/responses/200/content/application~1json/$ref: Only a media type of this document\'s components is read behind "$ref"; #/components/schemas/Pet gives no class.',
            'warning ' . self::AT . 'get/responses/201/content/application~1json/$ref: "$ref" #/components/mediaTypes/Missing names no media type; it gives no class.',
            'warning ' . self::AT . 'get/responses/202/content/application~1json/$ref: Only a media type of this document\'s components is read behind "$ref"; #/components/responses/Pet gives no class.',
            'warning ' . self::AT . 'get/responses/203/content/application~1json/$ref: "$ref" #/components/mediaTypes/List names no media type; it gives no class.',
        ], $this->messages($output));
        self::assertSame(['Pet'], array_column($this->summary($output), 0));
    }

    public function testNamesAnOperationWithABlankIdAfterItsPlace(): void
    {
        $output = $this->load(['paths' => ['/pets/{id}' => ['get' => ['operationId' => ' ', 'responses' => ['200' => $this->json(self::OBJECT)]]]]]);

        self::assertSame(['get /pets/{id} 200 Response'], array_column($this->summary($output), 0));
    }

    public function testLeavesASchemaThatOnlyNamesAnotherToIt(): void
    {
        $output = $this->load([
            'paths' => ['/pets/{id}' => ['get' => ['operationId' => 'a', 'responses' => [
                '200' => $this->json(['$ref' => '#/components/schemas/Pet']),
                '201' => $this->json(['$ref' => '#/components/schemas/Pet', 'description' => 'Created.']),
                '202' => $this->json(['allOf' => [['$ref' => '#/components/schemas/Pet']], 'description' => 'Accepted.', 'title' => 'A', 'nullable' => true, '$comment' => 'c', 'x-examples' => []]),
                '210' => $this->json(['$ref' => '#/components/schemas/Pet', 'allOf' => [['$ref' => '#/components/schemas/Pet']]]),
                '211' => $this->json(['$ref' => '#/components/schemas/Pet', 'required' => ['a']]),
                '203' => $this->json(['allOf' => [['$ref' => '#/components/schemas/Pet']], 'required' => ['a']]),
                '204' => $this->json(['allOf' => [['$ref' => '#/components/schemas/Pet'], ['$ref' => '#/components/schemas/Pet']]]),
                '205' => $this->json(['allOf' => [['$ref' => '#/components/schemas/Pet', 'description' => 'Pet.']]]),
                '206' => $this->json(['allOf' => ['a' => ['$ref' => '#/components/schemas/Pet']]]),
                '207' => $this->json(['allOf' => [true]]),
                '208' => $this->json(['allOf' => true]),
                '209' => $this->json(['allOf' => [['$ref' => '#/components/schemas/Pet']], 'nox-a' => 1]),
            ]]]],
            'components' => ['schemas' => ['Pet' => self::OBJECT]],
        ]);

        self::assertSame([
            ['Pet', self::SPEC . '#/components/schemas/Pet'],
            ['a 210 Response', self::AT . 'get/responses/210/content/application~1json/schema'],
            ['a 211 Response', self::AT . 'get/responses/211/content/application~1json/schema'],
            ['a 203 Response', self::AT . 'get/responses/203/content/application~1json/schema'],
            ['a 204 Response', self::AT . 'get/responses/204/content/application~1json/schema'],
            ['a 205 Response', self::AT . 'get/responses/205/content/application~1json/schema'],
            ['a 206 Response', self::AT . 'get/responses/206/content/application~1json/schema'],
            ['a 207 Response', self::AT . 'get/responses/207/content/application~1json/schema'],
            ['a 208 Response', self::AT . 'get/responses/208/content/application~1json/schema'],
            ['a 209 Response', self::AT . 'get/responses/209/content/application~1json/schema'],
        ], $this->summary($output));
    }

    /**
     * @dataProvider annotations
     */
    public function testReadsAnAnnotatedReferenceAsTheReference(string $keyword): void
    {
        $output = $this->load([
            'paths' => ['/pets/{id}' => ['get' => ['operationId' => 'a', 'responses' => [
                '200' => $this->json(['$ref' => '#/components/schemas/Pet', $keyword => 'x']),
                '201' => $this->json(['allOf' => [['$ref' => '#/components/schemas/Pet']], $keyword => 'x']),
            ]]]],
            'components' => ['schemas' => ['Pet' => self::OBJECT]],
        ]);

        self::assertSame(['Pet'], array_column($this->summary($output), 0));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function annotations(): iterable
    {
        foreach (['title', 'description', 'example', 'examples', 'deprecated', 'externalDocs', '$comment', 'nullable', 'readOnly', 'writeOnly', 'x-examples'] as $keyword) {
            yield $keyword => [$keyword];
        }
    }

    public function testReadsABodyBehindARefOnlyInTheComponents(): void
    {
        $output = $this->load([
            'paths' => [
                '/pets/{id}' => ['post' => [
                    'operationId' => 'a',
                    'requestBody' => ['$ref' => '#/components/requestBodies/NewPet'] + $this->json(self::OBJECT),
                    'responses' => [
                        '200' => ['$ref' => '#/components/responses/Pet'],
                        '404' => ['$ref' => 'common.yaml#/NotFound'],
                        '410' => ['$ref' => '#/components/responsesOld/Gone'],
                        '411' => ['$ref' => '#/components/responses/Pet/content'],
                        '412' => ['$ref' => '#Anchor'],
                        '413' => ['$ref' => '#/components/responses/Missing'],
                        '414' => ['$ref' => '#/components/responses/Text'],
                        '415' => ['$ref' => '#/components/responses/List'],
                    ],
                ]],
                '/cats' => ['$ref' => 'cats.yaml#/paths/~1cats', 'get' => ['responses' => ['200' => $this->json(self::OBJECT)]]],
            ],
            'components' => ['requestBodies' => ['NewPet' => $this->json(self::OBJECT)], 'responses' => ['Pet' => $this->json(self::OBJECT), 'Text' => 'plain', 'List' => [['content' => []]]]],
        ]);

        self::assertSame([
            'warning ' . self::AT . 'post/responses/404/$ref: Only a request body or response of this document\'s components is read behind "$ref"; common.yaml#/NotFound gives no class.',
            'warning ' . self::AT . 'post/responses/410/$ref: Only a request body or response of this document\'s components is read behind "$ref"; #/components/responsesOld/Gone gives no class.',
            'warning ' . self::AT . 'post/responses/411/$ref: Only a request body or response of this document\'s components is read behind "$ref"; #/components/responses/Pet/content gives no class.',
            'warning ' . self::AT . 'post/responses/412/$ref: Only a request body or response of this document\'s components is read behind "$ref"; #Anchor gives no class.',
            'warning ' . self::AT . 'post/responses/413/$ref: "$ref" #/components/responses/Missing names no request body or response; it gives no class.',
            'warning ' . self::AT . 'post/responses/414/$ref: "$ref" #/components/responses/Text names no request body or response; it gives no class.',
            'warning ' . self::AT . 'post/responses/415/$ref: "$ref" #/components/responses/List names no request body or response; it gives no class.',
            'warning ' . self::SPEC . '#/paths/~1cats/$ref: A path item behind "$ref" is not read; its operations give no classes.',
        ], $this->messages($output));
        self::assertSame(['NewPet Request', 'Pet Response'], array_column($this->summary($output), 0));
    }

    public function testReadsOnlyTheOperationsTheFilterSelects(): void
    {
        $operation = static fn (string $id): array => ['operationId' => $id, 'requestBody' => ['content' => ['application/json' => ['schema' => self::OBJECT]]]];
        $output = $this->load(
            [
                'paths' => ['/pets/{id}' => [
                    'post' => $operation('createPet'),
                    'put' => ['operationId' => 'updatePet', 'requestBody' => ['$ref' => '#/components/requestBodies/OldPet']],
                    'patch' => ['operationId' => ' patchPet ', 'requestBody' => ['$ref' => '#/components/requestBodies/NewPet']],
                    'delete' => ['requestBody' => $this->json(self::OBJECT)],
                ]],
                'components' => ['requestBodies' => ['OldPet' => $this->json(self::OBJECT), 'NewPet' => $this->json(self::OBJECT)]],
            ],
            new OperationFilter(['*Pet', 'delete /pets/*'], ['update*']),
        );

        self::assertSame(['createPet Request', 'delete /pets/{id} Request', 'NewPet Request'], array_column($this->summary($output), 0));
    }

    public function testReadsNoOperationsUnlessAsked(): void
    {
        $document = ['openapi' => '3.1.0', 'paths' => ['/pets/{id}' => ['get' => ['responses' => ['200' => $this->json(self::OBJECT)]]]]];
        $output = (new Action(new InMemoryDocumentLoader([self::SPEC => $document]), new SchemaParser()))(new Input(ConfigMother::config(ConfigMother::source(self::SPEC))));

        self::assertSame([], $output->graph()->all());
        self::assertSame(['warning ' . self::SPEC . '#/components/schemas: The specification has no components/schemas; nothing to generate.'], $this->messages($output));
    }

    public function testSkipsWhatIsNoObject(): void
    {
        $output = $this->load(['paths' => [
            '/pets/{id}' => ['get' => 'nothing', 'post' => ['requestBody' => 'nothing', 'responses' => ['200' => ['content' => ['application/json' => 'nothing']]]]],
            '/cats' => 'nothing',
        ], 'components' => ['requestBodies' => 'nothing']]);

        self::assertSame([], $this->summary($output));
        self::assertSame(['warning ' . self::SPEC . '#/components/schemas: The specification has no components/schemas; nothing to generate.'], $this->messages($output));
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return array{content: array{'application/json': array{schema: array<string, mixed>}}}
     */
    private function json(array $schema): array
    {
        return ['content' => ['application/json' => ['schema' => $schema]]];
    }

    /**
     * @param array<string, mixed> $root the document without its version
     */
    private function load(array $root, ?OperationFilter $filter = null): Output
    {
        $source = new SourceConfig(self::SPEC, 'App\Dto', '/project/src/Dto', ['*'], [], [], $filter ?? OperationFilter::all());
        $documents = [self::SPEC => ['openapi' => '3.1.0'] + $root];

        return (new Action(new InMemoryDocumentLoader($documents), new SchemaParser()))(new Input(ConfigMother::config($source)));
    }

    /**
     * @return list<string>
     */
    private function messages(Output $output): array
    {
        return array_map(static fn (Diagnostic $d): string => $d->toString(), $output->diagnostics()->all());
    }

    /**
     * @return list<array{string, string}> name and location
     */
    private function summary(Output $output): array
    {
        return array_map(static fn (ResolvedSchema $schema): array => [$schema->name(), $schema->location()->toString()], $output->graph()->all());
    }
}
