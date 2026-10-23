<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Application\Service\Model;

use MSSTC4PHP\DtoGenerator\Application\Config\ViewSuffixes;
use MSSTC4PHP\DtoGenerator\Domain\Model\PropertyModel;
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

    public function testSpellsSignedNamesAlikeInEveryView(): void
    {
        $output = ModelFixture::views([
            'Reactions' => ['type' => 'object', 'properties' => [
                '+1' => ['type' => 'integer', 'readOnly' => true],
                '-1' => ['type' => 'integer'],
            ]],
        ]);

        $names = [];
        foreach ($output->classes() as $class) {
            $names[$class->model()->name()->shortName()] = array_map(static fn (PropertyModel $property): string => $property->name(), $class->model()->properties());
        }

        self::assertSame(['ReactionsRead' => ['plus1', 'minus1'], 'ReactionsWrite' => ['minus1']], $names);
    }

    public function testBuildsAReadAndAWriteViewOfEveryDependentClass(): void
    {
        $output = ModelFixture::views(self::PETS);

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
        $output = ModelFixture::views([
            'Animal' => ['oneOf' => [['$ref' => '#/components/schemas/Fish'], ['$ref' => '#/components/schemas/Bird']], 'discriminator' => ['propertyName' => 'kind']],
            'Fish' => ['type' => 'object', 'required' => ['kind'], 'properties' => [
                'kind' => ['type' => 'string'],
                'caught' => ['type' => 'string', 'readOnly' => true],
                'colour' => ['$ref' => '#/components/schemas/Colour'],
            ]],
            'Bird' => ['type' => 'object', 'required' => ['kind'], 'properties' => ['kind' => ['type' => 'string']]],
            'Colour' => ['type' => 'string', 'enum' => ['red', 'blue']],
        ], new ViewSuffixes('Response', 'Request'));
        $hierarchy = ModelFixture::hierarchy($output);

        self::assertSame('abstract by kind {Fish: App\Dto\FishResponse, Bird: App\Dto\BirdResponse}', $hierarchy['App\Dto\AnimalResponse']);
        self::assertSame('abstract by kind {Fish: App\Dto\FishRequest, Bird: App\Dto\BirdRequest}', $hierarchy['App\Dto\AnimalRequest']);
        self::assertSame('final extends App\Dto\AnimalRequest', $hierarchy['App\Dto\BirdRequest']);
        self::assertCount(6, $hierarchy);
        self::assertSame(['App\Dto\Colour'], ModelFixture::enums($output));
    }

    public function testSplitsTheClassesThatImplementADependentInterface(): void
    {
        $ref = static fn (string $name): array => ['$ref' => '#/components/schemas/' . $name];
        $output = ModelFixture::views([
            'Item' => ['oneOf' => [$ref('Plain'), $ref('Extended')], 'discriminator' => ['propertyName' => 'type']],
            'Plain' => ['type' => 'object', 'properties' => ['type' => ['type' => 'string', 'const' => 'Plain'], 'id' => ['type' => 'string', 'readOnly' => true]]],
            'Base' => ['type' => 'object', 'properties' => ['f' => ['type' => 'string']]],
            'Extended' => ['allOf' => [$ref('Base'), ['type' => 'object', 'properties' => ['type' => ['type' => 'string', 'const' => 'Extended']]]]],
        ]);

        self::assertSame([], ModelFixture::messages($output));
        $hierarchy = ModelFixture::hierarchy($output);
        self::assertSame('final extends App\\Dto\\Base implements App\\Dto\\ItemRead', $hierarchy['App\\Dto\\ExtendedRead']);
        self::assertSame('final extends App\\Dto\\Base implements App\\Dto\\ItemWrite', $hierarchy['App\\Dto\\ExtendedWrite']);
    }

    public function testSharesAClassThatImplementsAnInterfaceOfNoDirection(): void
    {
        $ref = static fn (string $name): array => ['$ref' => '#/components/schemas/' . $name];
        $output = ModelFixture::views([
            'Item' => ['oneOf' => [$ref('Plain'), $ref('Extended')], 'discriminator' => ['propertyName' => 'type']],
            'Plain' => ['type' => 'object', 'properties' => ['type' => ['type' => 'string', 'const' => 'Plain']]],
            'Base' => ['type' => 'object', 'properties' => ['f' => ['type' => 'string']]],
            'Extended' => ['allOf' => [$ref('Base'), ['type' => 'object', 'properties' => ['type' => ['type' => 'string', 'const' => 'Extended']]]]],
            'Account' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string', 'readOnly' => true]]],
        ]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame('final implements App\\Dto\\Item', ModelFixture::hierarchy($output)['App\\Dto\\Plain']);
    }

    public function testNamesAnOverriddenClassWithTheSuffixAndReportsAMarkedBothWaysPropertyOnce(): void
    {
        $output = ModelFixture::views([
            'Pet' => ['type' => 'object', 'x-php-class-name' => 'Animal', 'properties' => [
                'id' => ['type' => 'string', 'readOnly' => true, 'writeOnly' => true],
                'secret' => ['type' => 'string', 'writeOnly' => true],
            ]],
        ]);

        self::assertSame(['App\Dto\AnimalRead' => ['id: string|null'], 'App\Dto\AnimalWrite' => ['id: string|null', 'secret: string|null']], ModelFixture::classes($output));
        self::assertSame(['warning /project/api/openapi.yaml#/components/schemas/Pet/properties/id: "readOnly" and "writeOnly" are both true; the property is in both views.'], ModelFixture::messages($output));
    }

    public function testReportsAViewNamedLikeAnotherClass(): void
    {
        $output = ModelFixture::views([
            'PetRead' => ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]],
            'Pet' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string', 'readOnly' => true]]],
        ]);

        self::assertSame([
            'error /project/api/openapi.yaml#/components/schemas/Pet: Class App\Dto\PetRead is already generated from /project/api/openapi.yaml#/components/schemas/PetRead; set "x-php-class-name" on one of them, in the document or "classNames" of the source, or change dto.readWriteSuffixes.',
        ], ModelFixture::messages($output));
    }

    public function testReportsAClassNamedLikeAViewDeclaredBefore(): void
    {
        $output = ModelFixture::views([
            'Pet' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string', 'readOnly' => true]]],
            'Other' => ['type' => 'object', 'x-php-class-name' => 'PetWrite', 'properties' => ['a' => ['type' => 'string']]],
            'Plain' => ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]],
            'Same' => ['type' => 'object', 'x-php-class-name' => 'Plain', 'properties' => ['a' => ['type' => 'string']]],
        ]);

        self::assertSame([
            'error /project/api/openapi.yaml#/components/schemas/Same: Class App\Dto\Plain is already generated from /project/api/openapi.yaml#/components/schemas/Plain; set "x-php-class-name" on one of them, in the document or "classNames" of the source.',
            'error /project/api/openapi.yaml#/components/schemas/Other: Class App\Dto\PetWrite is already generated from /project/api/openapi.yaml#/components/schemas/Pet; set "x-php-class-name" on one of them, in the document or "classNames" of the source, or change dto.readWriteSuffixes.',
        ], ModelFixture::messages($output));
    }

    public function testSplitsAClassWhoseFlagIsOnALaterDeclaration(): void
    {
        $extends = ModelFixture::views([
            'Child' => ['properties' => ['id' => ['type' => 'integer', 'readOnly' => true]], 'allOf' => [['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'name' => ['type' => 'string']]]]],
        ]);
        $merged = ModelFixture::views([
            'Base' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]],
            'Child' => ['allOf' => [['$ref' => '#/components/schemas/Base'], ['type' => 'object', 'properties' => ['id' => ['type' => 'integer', 'readOnly' => true], 'name' => ['type' => 'string']]]]],
        ], null, 'split', 'merge');

        self::assertSame(['App\Dto\ChildRead' => ['id: int|null', 'name: string|null'], 'App\Dto\ChildWrite' => ['name: string|null']], ModelFixture::classes($extends));
        self::assertSame(['id: int|null', 'name: string|null'], ModelFixture::classes($merged)['App\Dto\ChildRead']);
        self::assertSame(['name: string|null'], ModelFixture::classes($merged)['App\Dto\ChildWrite']);
        self::assertSame(['id: int|null'], ModelFixture::classes($merged)['App\Dto\Base']);
    }

    public function testKeepsAPropertyMarkedEachWayByAnotherMemberInBothViews(): void
    {
        $output = ModelFixture::views([
            'Child' => ['allOf' => [
                ['type' => 'object', 'properties' => ['x' => ['type' => 'string', 'readOnly' => true], 'y' => ['type' => 'string']]],
                ['type' => 'object', 'properties' => ['x' => ['type' => 'string', 'writeOnly' => true], 'z' => ['type' => 'string', 'readOnly' => true]]],
            ]],
        ], null, 'split', 'merge');

        self::assertSame([
            'App\Dto\ChildRead' => ['x: string|null', 'y: string|null', 'z: string|null'],
            'App\Dto\ChildWrite' => ['x: string|null', 'y: string|null'],
        ], ModelFixture::classes($output));
        self::assertSame(['warning /project/api/openapi.yaml#/components/schemas/Child/allOf/1/properties/x: "readOnly" and "writeOnly" are both true; the property is in both views.'], ModelFixture::messages($output));
    }

    public function testSplitsASchemaWhoseNameAnotherTook(): void
    {
        $output = ModelFixture::views([
            'Pet' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer', 'readOnly' => true]]],
            'pet' => ['type' => 'object', 'properties' => ['secret' => ['type' => 'string', 'writeOnly' => true], 'name' => ['type' => 'string']]],
        ]);

        // The write build's refusal of PetWrite is the same problem, named after the read view.
        self::assertSame([
            'error /project/api/openapi.yaml#/components/schemas/pet: Class App\Dto\PetRead is already generated from /project/api/openapi.yaml#/components/schemas/Pet; set "x-php-class-name" on one of them, in the document or "classNames" of the source, or change dto.readWriteSuffixes.',
        ], ModelFixture::messages($output));
    }

    public function testDeclaresNoInlineClassForAPropertyOfTheOtherView(): void
    {
        $output = ModelFixture::views([
            'Pet' => ['type' => 'object', 'properties' => [
                'owner' => ['type' => 'object', 'writeOnly' => true, 'properties' => ['name' => ['type' => 'string'], 'since' => ['type' => 'string', 'readOnly' => true]]],
                'extra' => ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]],
            ], 'additionalProperties' => ['type' => 'object', 'readOnly' => true, 'properties' => ['b' => ['type' => 'string']]]],
        ]);

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
        $output = ModelFixture::views([
            'Pet' => ['allOf' => [
                ['type' => 'object', 'properties' => ['a' => ['type' => 'string'], 'id' => ['type' => 'integer', 'readOnly' => true]]],
                ['type' => 'object', 'properties' => ['a' => ['type' => 'integer']]],
            ]],
        ], null, 'split', 'merge');

        self::assertSame(['error /project/api/openapi.yaml#/components/schemas/Pet/allOf/1/properties/a: Property "a" of App\Dto\PetRead is int|null here, but string|null in an earlier allOf member.'], ModelFixture::messages($output));
    }

    public function testReportsTheFlagsOfAModelThatNeedsNoViews(): void
    {
        $output = ModelFixture::views([
            'Pet' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer', 'readOnly' => 'yes'], 'both' => ['type' => 'string', 'readOnly' => true, 'writeOnly' => true]]],
        ]);

        self::assertSame(['App\Dto\Pet' => ['id: int|null', 'both: string|null']], ModelFixture::classes($output));
        self::assertSame([
            'warning /project/api/openapi.yaml#/components/schemas/Pet/properties/id/readOnly: "readOnly" must be true or false; it is ignored.',
            'warning /project/api/openapi.yaml#/components/schemas/Pet/properties/both: "readOnly" and "writeOnly" are both true; the property is in both views.',
        ], ModelFixture::messages($output));
    }

    public function testSplitsAClassWhoseUndeclaredPropertiesAreDirected(): void
    {
        $output = ModelFixture::views([
            'Pet' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']], 'additionalProperties' => ['type' => 'string', 'readOnly' => true]],
        ]);

        self::assertSame([
            'App\Dto\PetRead' => ['name: string|null', 'additionalProperties: array<array-key, string>'],
            'App\Dto\PetWrite' => ['name: string|null'],
        ], ModelFixture::classes($output));
    }

    public function testSplitsASchemaNamedLikeOneWhoseClassDoesNotDependOnTheDirection(): void
    {
        $named = ModelFixture::views([
            'Pet' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
            'pet' => ['type' => 'object', 'properties' => [
                'id' => ['type' => 'integer', 'readOnly' => true],
                'flag' => ['type' => 'string', 'writeOnly' => 'yes'],
            ]],
            'Holder' => ['type' => 'object', 'properties' => ['p' => ['$ref' => '#/components/schemas/pet']]],
            'Other' => ['type' => 'object', 'properties' => ['o' => ['type' => 'string', 'readOnly' => true]]],
        ]);
        $inline = ModelFixture::views([
            'PetOwner' => ['type' => 'object', 'properties' => ['x' => ['type' => 'string']]],
            'Pet' => ['type' => 'object', 'properties' => ['owner' => ['type' => 'object', 'properties' => ['since' => ['type' => 'string', 'readOnly' => true]]]]],
        ]);

        self::assertSame(
            ['App\Dto\Pet', 'App\Dto\PetRead', 'App\Dto\HolderRead', 'App\Dto\OtherRead', 'App\Dto\PetWrite', 'App\Dto\HolderWrite', 'App\Dto\OtherWrite'],
            array_keys(ModelFixture::classes($named)),
        );
        self::assertSame([
            'warning /project/api/openapi.yaml#/components/schemas/pet/properties/flag/writeOnly: "writeOnly" must be true or false; it is ignored.',
        ], ModelFixture::messages($named));
        self::assertSame([], ModelFixture::messages($inline), 'the views name the inline classes apart');
    }

    public function testNumbersANameThatOnlyLetterCaseTellsApartInEveryView(): void
    {
        $output = ModelFixture::views([
            'Notary' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
            'notary' => ['type' => 'object', 'properties' => ['code' => ['type' => 'string']]],
        ]);

        self::assertSame(['App\Dto\Notary', 'App\Dto\Notary2'], array_keys(ModelFixture::classes($output)));
        self::assertSame(
            ['warning /project/api/openapi.yaml#/components/schemas/notary: Class App\Dto\Notary is already generated from /project/api/openapi.yaml#/components/schemas/Notary, so this one becomes App\Dto\Notary2; set "x-php-class-name" to name it, in the document or "classNames" of the source.'],
            ModelFixture::messages($output),
        );
    }

    public function testNumbersAClashingPropertyAlikeInEveryViewAndMember(): void
    {
        $output = ModelFixture::views([
            'User' => ['allOf' => [
                ['type' => 'object', 'properties' => ['url' => ['type' => 'string', 'readOnly' => true], 'URL' => ['type' => 'string']]],
                ['properties' => ['URL' => ['type' => 'string', 'minLength' => 1]]],
            ]],
        ]);

        $names = [];
        foreach ($output->classes() as $class) {
            $names[$class->model()->name()->shortName()] = array_map(static fn (PropertyModel $property): string => $property->name() . ' <- ' . $property->wireName(), $class->model()->properties());
        }

        self::assertSame(['UserRead' => ['url <- url', 'url2 <- URL'], 'UserWrite' => ['url2 <- URL']], $names);
        self::assertSame(
            ['warning /project/api/openapi.yaml#/components/schemas/User/allOf/0/properties/URL: Property "URL" becomes $url, which "url" already uses, so it is $url2; set "x-php-name" to name it, in the document or "propertyNames" of the source.'],
            ModelFixture::messages($output),
        );
    }

    public function testGivesAnExplicitNameToItsDeclarationOfANumberedProperty(): void
    {
        $output = ModelFixture::build([
            'User' => ['allOf' => [
                ['type' => 'object', 'properties' => ['url' => ['type' => 'string'], 'URL' => ['type' => 'string']]],
                ['properties' => ['URL' => ['type' => 'string', 'x-php-name' => 'link']]],
            ]],
        ]);

        self::assertContains(
            'error /project/api/openapi.yaml#/components/schemas/User/allOf/1/properties/URL: Property "URL" of App\\Dto\\User has another PHP name or default here than in an earlier allOf member.',
            ModelFixture::messages($output),
        );
    }

    public function testIgnoresTheDirectionOfASkippedProperty(): void
    {
        $output = ModelFixture::views([
            'Pet' => ['type' => 'object', 'properties' => [
                'name' => ['type' => 'string'],
                'internal' => ['type' => 'string', 'readOnly' => true, 'writeOnly' => true, 'x-php-skip' => true],
                'hidden' => ['type' => 'string', 'readOnly' => true, 'x-php-skip' => true],
            ]],
        ]);

        self::assertSame(['App\Dto\Pet' => ['name: string|null']], ModelFixture::classes($output));
        self::assertSame([], ModelFixture::messages($output));
    }

    public function testDeclaresNoInlineClassForAPropertyAnotherMemberSkips(): void
    {
        $schemas = ['Child' => ['allOf' => [
            ['type' => 'object', 'properties' => ['x' => ['type' => 'object', 'x-php-skip' => true], 'n' => ['type' => 'string']]],
            ['type' => 'object', 'properties' => ['x' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string', 'readOnly' => true]]]]],
        ]]];

        self::assertSame(['App\Dto\Child' => ['n: string|null']], ModelFixture::classes(ModelFixture::build($schemas)));
        self::assertSame(['App\Dto\Child' => ['n: string|null']], ModelFixture::classes(ModelFixture::views($schemas)));
    }

    public function testKeepsOneClassPerSchemaWithoutDirectedProperties(): void
    {
        $schemas = ['Tag' => ['type' => 'object', 'properties' => ['label' => ['type' => 'string']]]];

        self::assertSame(
            ModelFixture::classes(ModelFixture::build($schemas)),
            ModelFixture::classes(ModelFixture::views($schemas)),
        );
    }

    public function testIgnoresTheDirectionsOfASingleModel(): void
    {
        $output = ModelFixture::build(self::PETS);

        self::assertSame(['id: int', 'name: string', 'password: string', 'owner: App\Dto\PetOwner|null', 'tag: App\Dto\Tag|null'], ModelFixture::classes($output)['App\Dto\Pet']);
        self::assertSame([], ModelFixture::messages($output));
    }

    public function testSplitsASchemaThatAsksForItWhenTheRunKeepsOneModel(): void
    {
        $output = ModelFixture::build([
            'User' => ['type' => 'object', 'x-php-read-write' => 'split', 'properties' => [
                'id' => ['type' => 'integer', 'readOnly' => true],
                'name' => ['type' => 'string'],
                'password' => ['type' => 'string', 'writeOnly' => true],
            ]],
            'Holder' => ['type' => 'object', 'properties' => ['user' => ['$ref' => '#/components/schemas/User']]],
            'Other' => ['type' => 'object', 'properties' => [
                'id' => ['type' => 'integer', 'readOnly' => true],
                'both' => ['type' => 'string', 'readOnly' => true, 'writeOnly' => true],
            ]],
        ]);

        self::assertSame([
            'App\Dto\UserRead' => ['id: int|null', 'name: string|null'],
            'App\Dto\HolderRead' => ['user: App\Dto\UserRead|null'],
            'App\Dto\Other' => ['id: int|null', 'both: string|null'],
            'App\Dto\UserWrite' => ['name: string|null', 'password: string|null'],
            'App\Dto\HolderWrite' => ['user: App\Dto\UserWrite|null'],
        ], ModelFixture::classes($output));
        self::assertSame([], ModelFixture::messages($output));
    }

    public function testKeepsOneModelOfASchemaThatAsksForItWhenTheRunSplits(): void
    {
        $output = ModelFixture::views([
            'Pet' => ['type' => 'object', 'x-php-read-write' => 'single', 'properties' => [
                'id' => ['type' => 'integer', 'readOnly' => true],
                'both' => ['type' => 'string', 'readOnly' => true, 'writeOnly' => true],
                'owner' => ['type' => 'object', 'properties' => ['since' => ['type' => 'string']]],
            ]],
            'Tag' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer', 'readOnly' => true]]],
        ]);

        self::assertSame([
            'App\Dto\Pet' => ['id: int|null', 'both: string|null', 'owner: App\Dto\PetOwner|null'],
            'App\Dto\TagRead' => ['id: int|null'],
            'App\Dto\PetOwner' => ['since: string|null'],
            'App\Dto\TagWrite' => [],
        ], ModelFixture::classes($output));
        self::assertSame([], ModelFixture::messages($output));
    }

    public function testWarnsWhenASingleSchemaGetsViewsOfAClassItHolds(): void
    {
        $output = ModelFixture::views([
            'Holder' => ['type' => 'object', 'x-php-read-write' => 'single', 'properties' => [
                'pet' => ['$ref' => '#/components/schemas/Pet'],
                'secret' => ['type' => 'string', 'writeOnly' => true],
            ]],
            'Pet' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer', 'readOnly' => true]]],
        ]);

        self::assertSame([
            'App\Dto\HolderRead' => ['pet: App\Dto\PetRead|null', 'secret: string|null'],
            'App\Dto\PetRead' => ['id: int|null'],
            'App\Dto\HolderWrite' => ['pet: App\Dto\PetWrite|null', 'secret: string|null'],
            'App\Dto\PetWrite' => [],
        ], ModelFixture::classes($output));
        self::assertSame([
            'warning /project/api/openapi.yaml#/components/schemas/Holder/x-php-read-write: "x-php-read-write: single" is overridden: the class holds, extends or lists a class with read and write views, so it gets them too.',
        ], ModelFixture::messages($output));
    }

    public function testKeepsTheInlineValuesOfASingleSchemaInEachView(): void
    {
        $output = ModelFixture::views([
            'Bag' => ['type' => 'object', 'x-php-read-write' => 'single', 'properties' => ['name' => ['type' => 'string']], 'additionalProperties' => [
                'type' => 'object', 'readOnly' => true, 'properties' => ['a' => ['type' => 'string']],
            ]],
            'Tag' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer', 'readOnly' => true]]],
        ]);

        self::assertSame([
            'App\Dto\Bag' => ['name: string|null', 'additionalProperties: array<array-key, App\Dto\BagAdditionalProperty>'],
            'App\Dto\TagRead' => ['id: int|null'],
            'App\Dto\BagAdditionalProperty' => ['a: string|null'],
            'App\Dto\TagWrite' => [],
        ], ModelFixture::classes($output));
        self::assertSame([], ModelFixture::messages($output));
    }

    public function testSplitsAnInlineObjectThatAsksForItAndItsOwner(): void
    {
        $output = ModelFixture::views([
            'Pet' => ['type' => 'object', 'properties' => [
                'owner' => ['type' => 'object', 'x-php-read-write' => 'split', 'properties' => [
                    'since' => ['type' => 'string', 'readOnly' => true],
                    'name' => ['type' => 'string'],
                ]],
            ]],
        ], null, 'single');

        self::assertSame([
            'App\Dto\PetRead' => ['owner: App\Dto\PetOwnerRead|null'],
            'App\Dto\PetOwnerRead' => ['since: string|null', 'name: string|null'],
            'App\Dto\PetWrite' => ['owner: App\Dto\PetOwnerWrite|null'],
            'App\Dto\PetOwnerWrite' => ['name: string|null'],
        ], ModelFixture::classes($output));
        self::assertSame([], ModelFixture::messages($output));
    }

    public function testMergesTheFlagsOfAnotherSchemaByTheModeOfTheClass(): void
    {
        $user = ['type' => 'object', 'properties' => ['id' => ['type' => 'integer', 'readOnly' => true], 'pw' => ['type' => 'string', 'writeOnly' => true]]];
        $admin = ['allOf' => [['$ref' => '#/components/schemas/User'], ['type' => 'object', 'properties' => ['role' => ['type' => 'string']]]]];
        $single = ModelFixture::views(['User' => $user + ['x-php-read-write' => 'split'], 'Admin' => $admin], null, 'single', 'merge');
        $split = ModelFixture::views(['User' => $user + ['x-php-read-write' => 'single'], 'Admin' => $admin + ['x-php-read-write' => 'split']], null, 'single', 'merge');

        self::assertSame(['id: int|null', 'pw: string|null', 'role: string|null'], ModelFixture::classes($single)['App\Dto\Admin']);
        self::assertSame(['App\Dto\UserRead', 'App\Dto\Admin', 'App\Dto\UserWrite'], array_keys(ModelFixture::classes($single)));
        self::assertSame([], ModelFixture::messages($single));
        self::assertSame([
            'App\Dto\User' => ['id: int|null', 'pw: string|null'],
            'App\Dto\AdminRead' => ['id: int|null', 'role: string|null'],
            'App\Dto\AdminWrite' => ['pw: string|null', 'role: string|null'],
        ], ModelFixture::classes($split));
        self::assertSame([], ModelFixture::messages($split));
    }

    public function testLeavesTheInlineObjectsOfASplitSchemaToTheRun(): void
    {
        $output = ModelFixture::build([
            'Pet' => ['type' => 'object', 'x-php-read-write' => 'split', 'properties' => [
                'id' => ['type' => 'integer', 'readOnly' => true],
                'owner' => ['type' => 'object', 'properties' => ['since' => ['type' => 'string', 'readOnly' => true]]],
            ]],
        ]);

        self::assertSame([
            'App\Dto\PetRead' => ['id: int|null', 'owner: App\Dto\PetOwner|null'],
            'App\Dto\PetOwner' => ['since: string|null'],
            'App\Dto\PetWrite' => ['owner: App\Dto\PetOwner|null'],
        ], ModelFixture::classes($output));
    }

    public function testReportsAnUnknownModeOnceAndFollowsTheRun(): void
    {
        $schemas = ['Pet' => ['type' => 'object', 'x-php-read-write' => 'both', 'properties' => ['id' => ['type' => 'integer', 'readOnly' => true]]]];
        $error = 'error /project/api/openapi.yaml#/components/schemas/Pet/x-php-read-write: "x-php-read-write" must be "single" or "split".';
        $single = ModelFixture::build($schemas);
        $split = ModelFixture::views($schemas);

        self::assertSame(['App\Dto\Pet'], array_keys(ModelFixture::classes($single)));
        self::assertSame([$error], ModelFixture::messages($single));
        self::assertSame(['App\Dto\PetRead', 'App\Dto\PetWrite'], array_keys(ModelFixture::classes($split)));
        self::assertSame([$error], ModelFixture::messages($split));
    }

    public function testNamesTheViewsOfASplitSchemaWithTheSuffixesOfASingleRun(): void
    {
        $output = ModelFixture::views(
            ['User' => ['type' => 'object', 'x-php-read-write' => 'split', 'properties' => ['id' => ['type' => 'integer', 'readOnly' => true]]]],
            new ViewSuffixes('Out', 'In'),
            'single',
        );

        self::assertSame(['App\Dto\UserOut', 'App\Dto\UserIn'], array_keys(ModelFixture::classes($output)));
    }

    public function testReportsOnlyTheNameOfASingleSchemaNamedLikeAView(): void
    {
        $output = ModelFixture::views([
            'Pet' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string', 'readOnly' => true]]],
            'PetRead' => ['type' => 'object', 'x-php-read-write' => 'single', 'properties' => ['a' => ['type' => 'string']]],
        ]);

        self::assertSame([
            'error /project/api/openapi.yaml#/components/schemas/PetRead: Class App\Dto\PetRead is already generated from /project/api/openapi.yaml#/components/schemas/Pet; set "x-php-class-name" on one of them, in the document or "classNames" of the source, or change dto.readWriteSuffixes.',
        ], ModelFixture::messages($output));
    }

    /**
     * @dataProvider singleSchemasWithViews
     *
     * @param array<string, array<array-key, mixed>> $schemas
     * @param list<string> $classes
     */
    public function testWarnsWhenASingleSchemaGetsViewsAnyWay(array $schemas, array $classes): void
    {
        $output = ModelFixture::views($schemas);

        self::assertSame($classes, array_keys(ModelFixture::classes($output)));
        self::assertSame([
            'warning /project/api/openapi.yaml#/components/schemas/Holder/x-php-read-write: "x-php-read-write: single" is overridden: the class holds, extends or lists a class with read and write views, so it gets them too.',
        ], ModelFixture::messages($output));
    }

    /**
     * @return iterable<string, array{array<string, array<array-key, mixed>>, list<string>}>
     */
    public static function singleSchemasWithViews(): iterable
    {
        $pet = ['type' => 'object', 'properties' => ['id' => ['type' => 'integer', 'readOnly' => true]]];

        yield 'extends' => [
            ['Pet' => $pet, 'Holder' => ['x-php-read-write' => 'single', 'allOf' => [['$ref' => '#/components/schemas/Pet'], ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]]]]],
            ['App\Dto\PetRead', 'App\Dto\HolderRead', 'App\Dto\PetWrite', 'App\Dto\HolderWrite'],
        ];
        yield 'lists in an array' => [
            ['Pet' => $pet, 'Holder' => ['type' => 'object', 'x-php-read-write' => 'single', 'properties' => ['pets' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Pet']]]]],
            ['App\Dto\PetRead', 'App\Dto\HolderRead', 'App\Dto\PetWrite', 'App\Dto\HolderWrite'],
        ];
        yield 'lists as a variant' => [
            [
                'Pet' => ['type' => 'object', 'required' => ['kind'], 'properties' => ['kind' => ['type' => 'string'], 'id' => ['type' => 'integer', 'readOnly' => true]]],
                'Holder' => ['x-php-read-write' => 'single', 'oneOf' => [['$ref' => '#/components/schemas/Pet']], 'discriminator' => ['propertyName' => 'kind']],
            ],
            ['App\Dto\PetRead', 'App\Dto\HolderRead', 'App\Dto\PetWrite', 'App\Dto\HolderWrite'],
        ];
    }

    /**
     * @dataProvider misplacedModes
     *
     * @param array<string, array<array-key, mixed>> $schemas
     */
    public function testReportsAMisplacedOrMistypedMode(array $schemas, string $message): void
    {
        self::assertSame([$message], ModelFixture::messages(ModelFixture::build($schemas)));
    }

    /**
     * @return iterable<string, array{array<string, array<array-key, mixed>>, string}>
     */
    public static function misplacedModes(): iterable
    {
        $at = 'warning /project/api/openapi.yaml#/components/schemas/';

        yield 'an enum' => [['Colour' => ['type' => 'string', 'enum' => ['red'], 'x-php-read-write' => 'split']], $at . 'Colour/x-php-read-write: "x-php-read-write" has no effect here.'];
        yield 'an alias' => [['Code' => ['type' => 'string', 'x-php-read-write' => 'split']], $at . 'Code/x-php-read-write: "x-php-read-write" has no effect here.'];
        yield 'an allOf member' => [
            ['Pet' => ['allOf' => [['type' => 'object', 'x-php-read-write' => 'split', 'properties' => ['a' => ['type' => 'string']]]]]],
            $at . 'Pet/allOf/0/x-php-read-write: "x-php-read-write" has no effect here.',
        ];
        yield 'not a string' => [
            ['Pet' => ['type' => 'object', 'x-php-read-write' => true, 'properties' => ['a' => ['type' => 'string']]]],
            'error /project/api/openapi.yaml#/components/schemas/Pet/x-php-read-write: "x-php-read-write" must be "single" or "split".',
        ];
    }
}
