<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Application\Service\Model;

use MSSTC4PHP\DtoGenerator\Application\Config\ViewSuffixes;
use MSSTC4PHP\DtoGenerator\Tests\Support\ModelFixture;
use PHPUnit\Framework\TestCase;

/**
 * readWriteModels: split (spec F1).
 */
final class BuildViewsTest extends TestCase
{
    private const PETS = [
        'Pet' => ['type' => 'object', 'required' => ['id', 'name', 'password'], 'properties' => [
            'id' => ['type' => 'integer', 'readOnly' => true],
            'name' => ['type' => 'string'],
            'password' => ['type' => 'string', 'writeOnly' => true],
            'owner' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string'], 'since' => ['type' => 'string', 'readOnly' => true]]],
            'tag' => ['$ref' => '#/components/schemas/Tag'],
        ]],
        'Tag' => ['type' => 'object', 'properties' => ['label' => ['type' => 'string']]],
        'Cat' => ['allOf' => [['$ref' => '#/components/schemas/Pet'], ['type' => 'object', 'properties' => ['lives' => ['type' => 'integer']]]]],
        'Kennel' => ['type' => 'object', 'properties' => ['pets' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Pet']]]],
    ];

    public function testBuildsAReadAndAWriteViewOfEveryDependentClass(): void
    {
        $output = ModelFixture::build(self::PETS, [], ['*'], 'extends', true, new ViewSuffixes());

        self::assertSame([
            'App\Dto\PetRead' => ['id: int', 'name: string', 'owner: App\Dto\PetOwnerRead|null', 'tag: App\Dto\Tag|null'],
            'App\Dto\Tag' => ['label: string|null'],
            'App\Dto\CatRead' => ['lives: int|null'],
            'App\Dto\KennelRead' => ['pets: list<App\Dto\PetRead>|null'],
            'App\Dto\PetOwnerRead' => ['name: string|null', 'since: string|null'],
            'App\Dto\PetWrite' => ['name: string', 'password: string', 'owner: App\Dto\PetOwnerWrite|null', 'tag: App\Dto\Tag|null'],
            'App\Dto\CatWrite' => ['lives: int|null'],
            'App\Dto\KennelWrite' => ['pets: list<App\Dto\PetWrite>|null'],
            'App\Dto\PetOwnerWrite' => ['name: string|null'],
        ], ModelFixture::classes($output));
        self::assertSame('final extends App\Dto\PetWrite', ModelFixture::hierarchy($output)['App\Dto\CatWrite']);
        self::assertSame([], ModelFixture::messages($output));
    }

    public function testListsTheViewsOfTheVariantsOfADiscriminatedBase(): void
    {
        $output = ModelFixture::build([
            'Animal' => ['oneOf' => [['$ref' => '#/components/schemas/Fish'], ['$ref' => '#/components/schemas/Bird']], 'discriminator' => ['propertyName' => 'kind']],
            'Fish' => ['type' => 'object', 'required' => ['kind'], 'properties' => [
                'kind' => ['type' => 'string'],
                'caught' => ['type' => 'string', 'readOnly' => true],
                'colour' => ['$ref' => '#/components/schemas/Colour'],
            ]],
            'Bird' => ['type' => 'object', 'required' => ['kind'], 'properties' => ['kind' => ['type' => 'string']]],
            'Colour' => ['type' => 'string', 'enum' => ['red', 'blue']],
        ], [], ['*'], 'extends', true, new ViewSuffixes('Response', 'Request'));
        $hierarchy = ModelFixture::hierarchy($output);

        self::assertSame('abstract by kind {Fish: App\Dto\FishResponse, Bird: App\Dto\BirdResponse}', $hierarchy['App\Dto\AnimalResponse']);
        self::assertSame('abstract by kind {Fish: App\Dto\FishRequest, Bird: App\Dto\BirdRequest}', $hierarchy['App\Dto\AnimalRequest']);
        self::assertSame('final extends App\Dto\AnimalRequest', $hierarchy['App\Dto\BirdRequest']);
        self::assertCount(6, $hierarchy);
        self::assertSame(['App\Dto\Colour'], ModelFixture::enums($output));
    }

    public function testNamesAnOverriddenClassWithTheSuffixAndReportsAMarkedBothWaysPropertyOnce(): void
    {
        $output = ModelFixture::build([
            'Pet' => ['type' => 'object', 'x-php-class-name' => 'Animal', 'properties' => [
                'id' => ['type' => 'string', 'readOnly' => true, 'writeOnly' => true],
                'secret' => ['type' => 'string', 'writeOnly' => true],
            ]],
        ], [], ['*'], 'extends', true, new ViewSuffixes());

        self::assertSame(['App\Dto\AnimalRead' => ['id: string|null'], 'App\Dto\AnimalWrite' => ['id: string|null', 'secret: string|null']], ModelFixture::classes($output));
        self::assertSame(['warning /project/api/openapi.yaml#/components/schemas/Pet/properties/id: "readOnly" and "writeOnly" are both true; the property is in both views.'], ModelFixture::messages($output));
    }

    public function testReportsAViewNamedLikeAnotherClass(): void
    {
        $output = ModelFixture::build([
            'PetRead' => ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]],
            'Pet' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string', 'readOnly' => true]]],
        ], [], ['*'], 'extends', true, new ViewSuffixes());

        self::assertSame([
            'error /project/api/openapi.yaml#/components/schemas/Pet: Class App\Dto\PetRead is already generated from /project/api/openapi.yaml#/components/schemas/PetRead; set "x-php-class-name" on one of them, or change dto.readWriteSuffixes.',
        ], ModelFixture::messages($output));
    }

    public function testReportsAClassNamedLikeAViewDeclaredBefore(): void
    {
        $output = ModelFixture::build([
            'Pet' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string', 'readOnly' => true]]],
            'Other' => ['type' => 'object', 'x-php-class-name' => 'PetWrite', 'properties' => ['a' => ['type' => 'string']]],
            'Plain' => ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]],
            'Same' => ['type' => 'object', 'x-php-class-name' => 'Plain', 'properties' => ['a' => ['type' => 'string']]],
        ], [], ['*'], 'extends', true, new ViewSuffixes());

        self::assertSame([
            'error /project/api/openapi.yaml#/components/schemas/Same: Class App\Dto\Plain is already generated from /project/api/openapi.yaml#/components/schemas/Plain; set "x-php-class-name" on one of them.',
            'error /project/api/openapi.yaml#/components/schemas/Other: Class App\Dto\PetWrite is already generated from /project/api/openapi.yaml#/components/schemas/Pet; set "x-php-class-name" on one of them, or change dto.readWriteSuffixes.',
        ], ModelFixture::messages($output));
    }

    public function testKeepsOneClassPerSchemaWithoutDirectedProperties(): void
    {
        $schemas = ['Tag' => ['type' => 'object', 'properties' => ['label' => ['type' => 'string']]]];

        self::assertSame(
            ModelFixture::classes(ModelFixture::build($schemas)),
            ModelFixture::classes(ModelFixture::build($schemas, [], ['*'], 'extends', true, new ViewSuffixes())),
        );
    }

    public function testIgnoresTheDirectionsOfASingleModel(): void
    {
        $output = ModelFixture::build(self::PETS);

        self::assertSame(['id: int', 'name: string', 'password: string', 'owner: App\Dto\PetOwner|null', 'tag: App\Dto\Tag|null'], ModelFixture::classes($output)['App\Dto\Pet']);
        self::assertSame([], ModelFixture::messages($output));
    }
}
