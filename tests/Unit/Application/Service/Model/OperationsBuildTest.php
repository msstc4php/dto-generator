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
        $output = ModelFixture::operations(['paths' => ['/pets' => ['post' => [
            'requestBody' => $this->json(['x-php-class-name' => 'NewPet'] + self::PET),
            'responses' => ['200' => $this->json(['title' => 'Ignored'] + self::PET)],
        ]]]]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(['App\Dto\NewPet', 'App\Dto\PostPets200Response'], array_keys(ModelFixture::classes($output)));
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
