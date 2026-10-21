<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Application\Service\Model;

use MSSTC4PHP\DtoGenerator\Tests\Support\ModelFixture;
use PHPUnit\Framework\TestCase;

/**
 * The classes of the schemas written in request bodies and responses.
 */
final class OperationsBuildTest extends TestCase
{
    private const AT = 'warning /project/api/openapi.yaml#/paths/~1pets/post/';

    private const PET = ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]];

    public function testNamesTheClassesAfterTheOperation(): void
    {
        $output = ModelFixture::operations(['paths' => ['/pets' => [
            'post' => [
                'operationId' => 'create_pet',
                'requestBody' => $this->json(['type' => 'object', 'properties' => ['kind' => ['enum' => ['cat', 'dog']]]]),
                'responses' => ['201' => $this->json(self::PET), 'default' => $this->json(['type' => 'array', 'items' => self::PET])],
            ],
            'get' => ['responses' => ['200' => $this->json(['enum' => ['a', 'b']])]],
        ]]]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame([
            'App\Dto\CreatePetRequest' => ['kind: App\Dto\CreatePetRequestKind|null'],
            'App\Dto\CreatePet201Response' => ['name: string|null'],
            'App\Dto\CreatePetDefaultResponseItem' => ['name: string|null'],
        ], ModelFixture::classes($output));
        self::assertSame(['App\Dto\GetPets200Response', 'App\Dto\CreatePetRequestKind'], ModelFixture::enums($output));
    }

    public function testGivesWayToAComponentHoldingTheName(): void
    {
        $output = ModelFixture::operations([
            'paths' => ['/pets' => ['post' => [
                'operationId' => 'createPet',
                'requestBody' => $this->json(self::PET),
                'responses' => ['200' => $this->json(['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]])],
            ]]],
            'components' => ['schemas' => ['CreatePetRequest' => self::PET, 'CreatePet200Response' => ['type' => 'object', 'properties' => ['n' => ['type' => 'string']]]]],
        ]);

        self::assertSame(
            [self::AT . 'responses/200/content/application~1json/schema: Class App\Dto\CreatePet200Response is already generated from /project/api/openapi.yaml#/components/schemas/CreatePet200Response, so this one becomes App\Dto\CreatePet200Response2; set "x-php-class-name" to name it, in the document or "classNames" of the source.'],
            ModelFixture::messages($output),
        );
        self::assertSame(['App\Dto\CreatePetRequest', 'App\Dto\CreatePet200Response', 'App\Dto\CreatePet200Response2'], array_keys(ModelFixture::classes($output)));
    }

    public function testTakesTheNameTheSchemaGivesItself(): void
    {
        $output = ModelFixture::operations([
            'paths' => ['/pets' => ['post' => [
                'requestBody' => $this->json(['x-php-class-name' => 'NewPet', 'title' => 'Other'] + self::PET),
                'responses' => ['200' => $this->json(['title' => 'pet list'] + self::PET), '201' => $this->json(['title' => 'Taken'] + self::PET)],
            ]]],
            'components' => ['schemas' => ['Taken' => ['type' => 'object', 'properties' => ['t' => ['type' => 'string']]]]],
        ]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(['App\Dto\Taken', 'App\Dto\NewPet', 'App\Dto\PetList', 'App\Dto\PostPets201Response'], array_keys(ModelFixture::classes($output)));
    }

    public function testLeavesTheNamesOfTheComponentsAsTheyAre(): void
    {
        $output = ModelFixture::operations([
            'paths' => ['/batch' => ['post' => ['operationId' => 'batch', 'responses' => ['default' => ['$ref' => '#/components/responses/Batch']]]]],
            'components' => [
                'schemas' => ['Batch' => ['type' => 'object', 'properties' => ['response' => self::PET]]],
                'responses' => ['Batch' => $this->json(['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]])],
            ],
        ]);

        self::assertSame(
            ['warning /project/api/openapi.yaml#/components/responses/Batch/content/application~1json/schema: Class App\Dto\BatchResponse is already generated from /project/api/openapi.yaml#/components/schemas/Batch/properties/response, so this one becomes App\Dto\BatchResponse2; set "x-php-class-name" to name it, in the document or "classNames" of the source.'],
            ModelFixture::messages($output),
        );
        self::assertSame(['name: string|null'], ModelFixture::classes($output)['App\Dto\BatchResponse']);
        self::assertSame(['id: int|null'], ModelFixture::classes($output)['App\Dto\BatchResponse2']);
    }

    public function testLeavesTheNamesTheComponentsGiveTheirInlineObjectsAsTheyAre(): void
    {
        $output = ModelFixture::operations([
            'paths' => ['/batch' => ['get' => ['operationId' => 'batch', 'responses' => ['200' => ['$ref' => '#/components/responses/Batch']]]]],
            'components' => [
                'schemas' => ['Batch' => ['type' => 'object', 'properties' => ['responseItem' => self::PET]]],
                'responses' => ['Batch' => $this->json(['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]]])],
            ],
        ]);

        self::assertSame(
            ['warning /project/api/openapi.yaml#/components/responses/Batch/content/application~1json/schema/items: Class App\Dto\BatchResponseItem is already generated from /project/api/openapi.yaml#/components/schemas/Batch/properties/responseItem, so this one becomes App\Dto\BatchResponseItem2; set "x-php-class-name" to name it, in the document or "classNames" of the source.'],
            ModelFixture::messages($output),
        );
        self::assertSame(['name: string|null'], ModelFixture::classes($output)['App\Dto\BatchResponseItem']);
        self::assertSame(['id: int|null'], ModelFixture::classes($output)['App\Dto\BatchResponseItem2']);
    }

    public function testLeavesTheNamesOfTheComponentsToTheValuesOfAMap(): void
    {
        $output = ModelFixture::operations([
            'paths' => ['/batch' => ['get' => ['operationId' => 'batch', 'responses' => ['200' => $this->json(['type' => 'object', 'additionalProperties' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]]])]]]],
            'components' => ['schemas' => ['Batch' => ['type' => 'object', 'properties' => ['_200ResponseValue' => self::PET]]]],
        ]);

        self::assertSame(['name: string|null'], ModelFixture::classes($output)['App\Dto\Batch200ResponseValue']);
        self::assertSame(['id: int|null'], ModelFixture::classes($output)['App\Dto\Batch200ResponseValue2']);
    }

    public function testNamesTheVariantsOfABodyDistributedOverAUnionByTheirPlace(): void
    {
        $output = ModelFixture::operations([
            'paths' => ['/pets' => ['post' => ['operationId' => 'createPet', 'requestBody' => $this->json([
                'allOf' => [['anyOf' => [['$ref' => '#/components/schemas/Cat'], ['$ref' => '#/components/schemas/Dog']]], ['properties' => ['note' => ['type' => 'string']]]],
            ])]]],
            'components' => ['schemas' => [
                'Cat' => self::PET,
                'Dog' => ['type' => 'object', 'properties' => ['bark' => ['type' => 'string']]],
                'CreatePetRequestCat' => ['type' => 'object', 'properties' => ['other' => ['type' => 'string']]],
            ]],
        ]);

        self::assertSame(
            ['warning /project/api/openapi.yaml#/paths/~1pets/post/requestBody/content/application~1json/schema/x-dto-variant/0/0: Class App\Dto\CreatePetRequestCat is already generated from /project/api/openapi.yaml#/components/schemas/CreatePetRequestCat, so this one becomes App\Dto\CreatePetRequestCat2; set "x-php-class-name" to name it, in the document or "classNames" of the source.'],
            ModelFixture::messages($output),
        );
        self::assertArrayHasKey('App\Dto\CreatePetRequestCat2', ModelFixture::classes($output));
        self::assertArrayHasKey('App\Dto\CreatePetRequestDog', ModelFixture::classes($output));
    }

    public function testMakesNoClassOfAScalar(): void
    {
        $output = ModelFixture::operations(['paths' => ['/pets' => ['post' => [
            'requestBody' => $this->json(['type' => 'string']),
            'responses' => ['200' => $this->json(['type' => 'array', 'items' => ['type' => 'integer']])],
        ]]]]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame([], ModelFixture::classes($output));
    }

    public function testKeepsTheStatusOfAnOperationIdWithoutCharactersForAName(): void
    {
        $output = ModelFixture::operations(['paths' => ['/pets' => ['post' => ['operationId' => '+', 'responses' => ['200' => $this->json(self::PET)]]]]]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(['App\Dto\_200Response'], array_keys(ModelFixture::classes($output)));
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
}
