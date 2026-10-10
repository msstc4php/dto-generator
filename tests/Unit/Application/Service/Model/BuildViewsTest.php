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

    public function testSplitsAClassWhoseFlagIsOnALaterDeclaration(): void
    {
        $extends = ModelFixture::build([
            'Child' => ['properties' => ['id' => ['type' => 'integer', 'readOnly' => true]], 'allOf' => [['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'name' => ['type' => 'string']]]]],
        ], [], ['*'], 'extends', true, new ViewSuffixes());
        $merged = ModelFixture::build([
            'Base' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]],
            'Child' => ['allOf' => [['$ref' => '#/components/schemas/Base'], ['type' => 'object', 'properties' => ['id' => ['type' => 'integer', 'readOnly' => true], 'name' => ['type' => 'string']]]]],
        ], [], ['*'], 'merge', true, new ViewSuffixes());

        self::assertSame(['App\Dto\ChildRead' => ['id: int|null', 'name: string|null'], 'App\Dto\ChildWrite' => ['name: string|null']], ModelFixture::classes($extends));
        self::assertSame(['id: int|null', 'name: string|null'], ModelFixture::classes($merged)['App\Dto\ChildRead']);
        self::assertSame(['name: string|null'], ModelFixture::classes($merged)['App\Dto\ChildWrite']);
        self::assertSame(['id: int|null'], ModelFixture::classes($merged)['App\Dto\Base']);
    }

    public function testKeepsAPropertyMarkedEachWayByAnotherMemberInBothViews(): void
    {
        $output = ModelFixture::build([
            'Child' => ['allOf' => [
                ['type' => 'object', 'properties' => ['x' => ['type' => 'string', 'readOnly' => true], 'y' => ['type' => 'string']]],
                ['type' => 'object', 'properties' => ['x' => ['type' => 'string', 'writeOnly' => true], 'z' => ['type' => 'string', 'readOnly' => true]]],
            ]],
        ], [], ['*'], 'merge', true, new ViewSuffixes());

        self::assertSame([
            'App\Dto\ChildRead' => ['x: string|null', 'y: string|null', 'z: string|null'],
            'App\Dto\ChildWrite' => ['x: string|null', 'y: string|null'],
        ], ModelFixture::classes($output));
        self::assertSame(['warning /project/api/openapi.yaml#/components/schemas/Child/allOf/1/properties/x: "readOnly" and "writeOnly" are both true; the property is in both views.'], ModelFixture::messages($output));
    }

    public function testSplitsASchemaWhoseNameAnotherTook(): void
    {
        $output = ModelFixture::build([
            'Pet' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer', 'readOnly' => true]]],
            'pet' => ['type' => 'object', 'properties' => ['secret' => ['type' => 'string', 'writeOnly' => true], 'name' => ['type' => 'string']]],
        ], [], ['*'], 'extends', true, new ViewSuffixes());

        // The write build's refusal of PetWrite is the same problem, named after the read view.
        self::assertSame([
            'error /project/api/openapi.yaml#/components/schemas/pet: Class App\Dto\PetRead is already generated from /project/api/openapi.yaml#/components/schemas/Pet; set "x-php-class-name" on one of them, or change dto.readWriteSuffixes.',
        ], ModelFixture::messages($output));
    }

    public function testDeclaresNoInlineClassForAPropertyOfTheOtherView(): void
    {
        $output = ModelFixture::build([
            'Pet' => ['type' => 'object', 'properties' => [
                'owner' => ['type' => 'object', 'writeOnly' => true, 'properties' => ['name' => ['type' => 'string'], 'since' => ['type' => 'string', 'readOnly' => true]]],
                'extra' => ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]],
            ], 'additionalProperties' => ['type' => 'object', 'readOnly' => true, 'properties' => ['b' => ['type' => 'string']]]],
        ], [], ['*'], 'extends', true, new ViewSuffixes());

        self::assertSame([
            'App\Dto\PetRead' => ['extra: App\Dto\PetExtra|null', 'additionalProperties: array<array-key, App\Dto\PetAdditionalProperty>'],
            'App\Dto\PetExtra' => ['a: string|null'],
            'App\Dto\PetAdditionalProperty' => ['b: string|null'],
            'App\Dto\PetWrite' => ['owner: App\Dto\PetOwnerWrite|null', 'extra: App\Dto\PetExtra|null'],
            'App\Dto\PetOwnerWrite' => ['name: string|null'],
        ], ModelFixture::classes($output));
    }

    public function testReportsAProblemOfADependentClassOnce(): void
    {
        $output = ModelFixture::build([
            'Pet' => ['allOf' => [
                ['type' => 'object', 'properties' => ['a' => ['type' => 'string'], 'id' => ['type' => 'integer', 'readOnly' => true]]],
                ['type' => 'object', 'properties' => ['a' => ['type' => 'integer']]],
            ]],
        ], [], ['*'], 'merge', true, new ViewSuffixes());

        self::assertSame(['error /project/api/openapi.yaml#/components/schemas/Pet/allOf/1/properties/a: Property "a" of App\Dto\PetRead is int|null here, but string|null in an earlier allOf member.'], ModelFixture::messages($output));
    }

    public function testReportsTheFlagsOfAModelThatNeedsNoViews(): void
    {
        $output = ModelFixture::build([
            'Pet' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer', 'readOnly' => 'yes'], 'both' => ['type' => 'string', 'readOnly' => true, 'writeOnly' => true]]],
        ], [], ['*'], 'extends', true, new ViewSuffixes());

        self::assertSame(['App\Dto\Pet' => ['id: int|null', 'both: string|null']], ModelFixture::classes($output));
        self::assertSame([
            'warning /project/api/openapi.yaml#/components/schemas/Pet/properties/id/readOnly: "readOnly" must be true or false; it is ignored.',
            'warning /project/api/openapi.yaml#/components/schemas/Pet/properties/both: "readOnly" and "writeOnly" are both true; the property is in both views.',
        ], ModelFixture::messages($output));
    }

    public function testSplitsAClassWhoseUndeclaredPropertiesAreDirected(): void
    {
        $output = ModelFixture::build([
            'Pet' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']], 'additionalProperties' => ['type' => 'string', 'readOnly' => true]],
        ], [], ['*'], 'extends', true, new ViewSuffixes());

        self::assertSame([
            'App\Dto\PetRead' => ['name: string|null', 'additionalProperties: array<array-key, string>'],
            'App\Dto\PetWrite' => ['name: string|null'],
        ], ModelFixture::classes($output));
    }

    public function testReportsANameTakenFromASchemaWhoseTakerDoesNotDependOnTheDirection(): void
    {
        $named = ModelFixture::build([
            'Pet' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
            'pet' => ['type' => 'object', 'properties' => [
                'id' => ['type' => 'integer', 'readOnly' => true],
                'flag' => ['type' => 'string', 'writeOnly' => 'yes'],
            ]],
            'Holder' => ['type' => 'object', 'properties' => ['p' => ['$ref' => '#/components/schemas/pet']]],
            'Other' => ['type' => 'object', 'properties' => ['o' => ['type' => 'string', 'readOnly' => true]]],
        ], [], ['*'], 'extends', true, new ViewSuffixes());
        $inline = ModelFixture::build([
            'PetOwner' => ['type' => 'object', 'properties' => ['x' => ['type' => 'string']]],
            'Pet' => ['type' => 'object', 'properties' => ['owner' => ['type' => 'object', 'properties' => ['since' => ['type' => 'string', 'readOnly' => true]]]]],
        ], [], ['*'], 'extends', true, new ViewSuffixes());

        self::assertSame(['App\Dto\Pet', 'App\Dto\Holder', 'App\Dto\OtherRead', 'App\Dto\OtherWrite'], array_keys(ModelFixture::classes($named)));
        self::assertSame([
            'error /project/api/openapi.yaml#/components/schemas/pet: Class App\Dto\Pet is already generated from /project/api/openapi.yaml#/components/schemas/Pet; set "x-php-class-name" on one of them.',
            'warning /project/api/openapi.yaml#/components/schemas/pet/properties/flag/writeOnly: "writeOnly" must be true or false; it is ignored.',
        ], ModelFixture::messages($named));
        self::assertSame([
            'error /project/api/openapi.yaml#/components/schemas/Pet/properties/owner: Class App\Dto\PetOwner is already generated from /project/api/openapi.yaml#/components/schemas/PetOwner; set "x-php-class-name" on one of them.',
        ], ModelFixture::messages($inline));
    }

    public function testIgnoresTheDirectionOfASkippedProperty(): void
    {
        $output = ModelFixture::build([
            'Pet' => ['type' => 'object', 'properties' => [
                'name' => ['type' => 'string'],
                'internal' => ['type' => 'string', 'readOnly' => true, 'writeOnly' => true, 'x-php-skip' => true],
                'hidden' => ['type' => 'string', 'readOnly' => true, 'x-php-skip' => true],
            ]],
        ], [], ['*'], 'extends', true, new ViewSuffixes());

        self::assertSame(['App\Dto\Pet' => ['name: string|null']], ModelFixture::classes($output));
        self::assertSame([], ModelFixture::messages($output));
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
