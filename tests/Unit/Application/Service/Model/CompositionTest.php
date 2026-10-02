<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Application\Service\Model;

use MSSTC4PHP\DtoGenerator\Application\Service\Model\Build\Output;
use MSSTC4PHP\DtoGenerator\Domain\Model\PropertyModel;
use MSSTC4PHP\DtoGenerator\Domain\Target\AllOfStrategy;
use MSSTC4PHP\DtoGenerator\Tests\Support\ModelFixture;
use PHPUnit\Framework\TestCase;

final class CompositionTest extends TestCase
{
    private const AT = '/project/api/openapi.yaml#/components/schemas/';

    private const PET = ['$ref' => '#/components/schemas/Pet'];

    public function testExtendsTheOneReferencedClass(): void
    {
        $output = ModelFixture::build([
            'Pet' => ['type' => 'object', 'required' => ['name'], 'properties' => ['name' => ['type' => 'string']]],
            'Cat' => ['allOf' => [self::PET, ['type' => 'object', 'required' => ['lives'], 'properties' => ['lives' => ['type' => 'integer']]]]],
        ]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(['App\Dto\Pet' => ['name: string'], 'App\Dto\Cat' => ['lives: int']], ModelFixture::classes($output));
        self::assertSame(['App\Dto\Pet' => 'open', 'App\Dto\Cat' => 'final extends App\Dto\Pet'], ModelFixture::hierarchy($output));
        self::assertSame(['name'], $this->inherited($output, 'App\Dto\Cat'));
        self::assertSame([], $this->inherited($output, 'App\Dto\Pet'));
    }

    public function testCollectsPropertiesOfInlineMembersAndOwnProperties(): void
    {
        $output = ModelFixture::build([
            'Pet' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
            'Cat' => [
                'allOf' => [['properties' => ['lives' => ['type' => 'integer']]], self::PET, ['required' => ['lives', 'whiskers']]],
                'properties' => ['whiskers' => ['type' => 'boolean']],
            ],
        ]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(['lives: int', 'whiskers: bool'], ModelFixture::classes($output)['App\Dto\Cat']);
        self::assertSame('final extends App\Dto\Pet', ModelFixture::hierarchy($output)['App\Dto\Cat']);
    }

    public function testChainsSeveralLevels(): void
    {
        $output = ModelFixture::build([
            'Animal' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']]],
            'Pet' => ['allOf' => [['$ref' => '#/components/schemas/Animal'], ['properties' => ['name' => ['type' => 'string']]]]],
            'Cat' => ['allOf' => [self::PET, ['properties' => ['lives' => ['type' => 'integer']]]]],
        ]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(
            ['App\Dto\Animal' => 'open', 'App\Dto\Pet' => 'open extends App\Dto\Animal', 'App\Dto\Cat' => 'final extends App\Dto\Pet'],
            ModelFixture::hierarchy($output),
        );
        self::assertSame(['id', 'name'], $this->inherited($output, 'App\Dto\Cat'));
    }

    public function testMergesSeveralReferences(): void
    {
        $output = ModelFixture::build([
            'A' => ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]],
            'B' => ['type' => 'object', 'required' => ['b'], 'properties' => ['b' => ['type' => 'integer']]],
            'C' => [
                'allOf' => [['$ref' => '#/components/schemas/A'], ['$ref' => '#/components/schemas/B']],
                'required' => ['a', 'c'],
                'properties' => ['c' => ['type' => 'boolean']],
            ],
        ]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(['a: string', 'b: int', 'c: bool'], ModelFixture::classes($output)['App\Dto\C']);
        self::assertSame(['App\Dto\A' => 'final', 'App\Dto\B' => 'final', 'App\Dto\C' => 'final'], ModelFixture::hierarchy($output));
    }

    public function testMergesNestedCompositionsAllTheWayDown(): void
    {
        $output = ModelFixture::build([
            'Pet' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
            'Cat' => ['allOf' => [self::PET, ['properties' => ['lives' => ['type' => 'integer']]]]],
            'Tag' => ['type' => 'object', 'properties' => ['label' => ['type' => 'string']]],
            'TaggedCat' => ['allOf' => [['$ref' => '#/components/schemas/Cat'], ['$ref' => '#/components/schemas/Tag']]],
        ]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(['name: string|null', 'lives: int|null', 'label: string|null'], ModelFixture::classes($output)['App\Dto\TaggedCat']);
    }

    public function testMergesWhenTheSchemaAsksForIt(): void
    {
        $output = ModelFixture::build([
            'Pet' => ['type' => 'object', 'required' => ['name'], 'properties' => ['name' => ['type' => 'string']]],
            'Cat' => ['allOf' => [self::PET, ['properties' => ['lives' => ['type' => 'integer']]]], 'x-php-all-of' => 'merge'],
        ]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(['name: string', 'lives: int|null'], ModelFixture::classes($output)['App\Dto\Cat']);
        self::assertSame(['App\Dto\Pet' => 'final', 'App\Dto\Cat' => 'final'], ModelFixture::hierarchy($output));
    }

    public function testMergesWhenTheConfigAsksForIt(): void
    {
        $output = ModelFixture::build(
            [
                'Pet' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
                'Cat' => ['allOf' => [self::PET, ['properties' => ['lives' => ['type' => 'integer']]]]],
                'Dog' => ['allOf' => [self::PET, ['properties' => ['bark' => ['type' => 'boolean']]]], 'x-php-all-of' => 'extends'],
            ],
            [],
            ['*'],
            AllOfStrategy::MERGE,
        );

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(
            ['App\Dto\Pet' => 'open', 'App\Dto\Cat' => 'final', 'App\Dto\Dog' => 'final extends App\Dto\Pet'],
            ModelFixture::hierarchy($output),
        );
    }

    public function testMergesAPropertyDeclaredTwiceWithTheSameType(): void
    {
        $output = ModelFixture::build([
            'A' => ['type' => 'object', 'properties' => ['x' => ['type' => 'string']]],
            'B' => ['type' => 'object', 'properties' => ['x' => ['type' => 'string', 'description' => 'Again.'], 'y' => ['type' => 'integer']]],
            'C' => ['allOf' => [['$ref' => '#/components/schemas/A'], ['$ref' => '#/components/schemas/B']]],
        ]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(['x: string|null', 'y: int|null'], ModelFixture::classes($output)['App\Dto\C']);
    }

    public function testReportsAMergedPropertyWithAnotherType(): void
    {
        $at = self::AT;
        $output = ModelFixture::build([
            'A' => ['type' => 'object', 'properties' => ['x' => ['type' => 'string']]],
            'B' => ['type' => 'object', 'properties' => ['x' => ['type' => 'integer']]],
            'C' => ['allOf' => [['$ref' => '#/components/schemas/A'], ['$ref' => '#/components/schemas/B']]],
        ]);

        self::assertSame(
            ["error {$at}B/properties/x: Property \"x\" of App\\Dto\\C is int|null here, but string|null in an earlier allOf member."],
            ModelFixture::messages($output),
        );
    }

    /**
     * @dataProvider problems
     *
     * @param array<string, array<array-key, mixed>> $schemas
     * @param list<string> $expected
     */
    public function testReportsCompositionProblems(array $schemas, array $expected): void
    {
        self::assertSame($expected, ModelFixture::messages(ModelFixture::build($schemas)));
    }

    /**
     * @return array<string, array{array<string, array<array-key, mixed>>, list<string>}>
     */
    public static function problems(): array
    {
        $at = self::AT;
        $pet = ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]];
        $lives = ['properties' => ['lives' => ['type' => 'integer']]];

        return [
            'unknown strategy' => [
                ['Pet' => $pet, 'Cat' => ['allOf' => [self::PET, $lives], 'x-php-all-of' => 'inherit']],
                ["error {$at}Cat/x-php-all-of: \"x-php-all-of\" must be \"extends\" or \"merge\"."],
            ],
            'member that is not an object' => [
                ['Email' => ['type' => 'string'], 'Cat' => ['allOf' => [['$ref' => '#/components/schemas/Email'], $lives]]],
                ["error {$at}Cat/allOf/0: An allOf member of a class must be an object schema."],
            ],
            'inline member that is not an object' => [
                ['Cat' => ['allOf' => [['type' => 'string'], $lives, ['properties' => ['name' => []]]]]],
                ["error {$at}Cat/allOf/0: An allOf member of a class must be an object schema."],
            ],
            'merge loop' => [
                [
                    'A' => ['allOf' => [['$ref' => '#/components/schemas/B'], ['properties' => ['a' => []]]], 'x-php-all-of' => 'merge'],
                    'B' => ['allOf' => [['$ref' => '#/components/schemas/A'], ['properties' => ['b' => []]]], 'x-php-all-of' => 'merge'],
                ],
                [
                    "error {$at}B/allOf/0: The allOf chain loops back to a schema it is already merging.",
                    "error {$at}A/allOf/0: The allOf chain loops back to a schema it is already merging.",
                ],
            ],
            'inheritance loop' => [
                [
                    'Plain' => ['type' => 'object', 'properties' => ['p' => []]],
                    'A' => ['allOf' => [['$ref' => '#/components/schemas/B'], ['properties' => ['a' => []]]]],
                    'B' => ['allOf' => [['$ref' => '#/components/schemas/A'], ['properties' => ['b' => []]]]],
                ],
                ["error {$at}A: Class App\\Dto\\A extends itself through App\\Dto\\B."],
            ],
            'class extending into a loop' => [
                [
                    'C' => ['allOf' => [['$ref' => '#/components/schemas/A'], ['properties' => ['c' => []]]]],
                    'A' => ['allOf' => [['$ref' => '#/components/schemas/B'], ['properties' => ['a' => []]]]],
                    'B' => ['allOf' => [['$ref' => '#/components/schemas/A'], ['properties' => ['b' => []]]]],
                ],
                ["error {$at}A: Class App\\Dto\\A extends itself through App\\Dto\\B."],
            ],
            'property named like an inherited one' => [
                ['Pet' => $pet, 'Cat' => ['allOf' => [self::PET, ['properties' => ['NAME' => ['type' => 'string', 'x-php-name' => 'Name']]]]]],
                ["error {$at}Cat/allOf/1/properties/NAME: Property \"NAME\" of App\\Dto\\Cat becomes \$Name, which App\\Dto\\Pet already declares; set \"x-php-name\"."],
            ],
            'property of a grandparent' => [
                [
                    'Animal' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']]],
                    'Pet' => ['allOf' => [['$ref' => '#/components/schemas/Animal'], ['properties' => ['name' => []]]]],
                    'Cat' => ['allOf' => [self::PET, ['properties' => ['id' => ['type' => 'integer']]]]],
                ],
                ["error {$at}Cat/allOf/1/properties/id: Property \"id\" of App\\Dto\\Cat is already declared by App\\Dto\\Animal."],
            ],
            'member that is a plain union' => [
                ['Either' => ['oneOf' => [['type' => 'string'], ['type' => 'integer']]], 'Cat' => ['allOf' => [['$ref' => '#/components/schemas/Either'], $lives]]],
                ["error {$at}Cat/allOf/0: An allOf member of a class must be an object schema."],
            ],
            'redeclared inherited property' => [
                ['Pet' => $pet, 'Cat' => ['allOf' => [self::PET, ['properties' => ['name' => ['type' => 'integer']]]]]],
                ["error {$at}Cat/allOf/1/properties/name: Property \"name\" of App\\Dto\\Cat is already declared by App\\Dto\\Pet."],
            ],
            'different mutability' => [
                ['Pet' => $pet + ['x-dto-mutable' => true], 'Cat' => ['allOf' => [self::PET, $lives]]],
                ["error {$at}Cat: App\\Dto\\Cat is immutable but its parent App\\Dto\\Pet is mutable; give both the same \"x-dto-mutable\"."],
            ],
            'discriminator without variants' => [
                ['Pet' => $pet + ['discriminator' => ['propertyName' => 'name']]],
                ["warning {$at}Pet/discriminator: The discriminator is ignored: no oneOf or anyOf lists variants and no named schema extends this one through allOf."],
            ],
            'required property of the parent' => [
                ['Pet' => $pet, 'Cat' => ['allOf' => [self::PET, ['required' => ['name', 'lives'], 'properties' => ['lives' => ['type' => 'integer']]]]]],
                ["warning {$at}Cat: Required property \"name\" belongs to the parent App\\Dto\\Pet, where extending cannot make it required; use \"x-php-all-of: merge\" to require it."],
            ],
            'allOf with oneOf on a named schema' => [
                [
                    'X' => $pet,
                    'P' => ['allOf' => [['$ref' => '#/components/schemas/X']], 'oneOf' => [['$ref' => '#/components/schemas/X']], 'discriminator' => ['propertyName' => 'name']],
                ],
                ["error {$at}P: \"allOf\" together with \"oneOf\" or \"anyOf\" is not supported."],
            ],
            'explicit extends with two parents' => [
                ['Pet' => $pet, 'Tag' => $pet, 'Cat' => ['allOf' => [self::PET, ['$ref' => '#/components/schemas/Tag'], $lives], 'x-php-all-of' => 'extends']],
                [
                    "warning {$at}Cat/x-php-all-of: \"x-php-all-of: extends\" needs exactly one \$ref to a generated class, so the members are merged.",
                ],
            ],
            'merged property skipped in one member' => [
                [
                    'A' => ['type' => 'object', 'properties' => ['x' => ['type' => 'string', 'x-php-skip' => true]]],
                    'B' => ['type' => 'object', 'properties' => ['x' => ['type' => 'integer'], 'y' => []]],
                    'C' => ['allOf' => [['$ref' => '#/components/schemas/A'], ['$ref' => '#/components/schemas/B']]],
                ],
                [],
            ],
            'merged required property twice' => [
                [
                    'A' => ['type' => 'object', 'required' => ['x'], 'properties' => ['x' => ['type' => 'string']]],
                    'B' => ['type' => 'object', 'required' => ['x'], 'properties' => ['x' => ['type' => 'string']]],
                    'C' => ['allOf' => [['$ref' => '#/components/schemas/A'], ['$ref' => '#/components/schemas/B']]],
                ],
                [],
            ],
            'merged number default written two ways' => [
                [
                    'A' => ['type' => 'object', 'properties' => ['a' => ['type' => 'number', 'default' => 1]]],
                    'B' => ['type' => 'object', 'properties' => ['a' => ['type' => 'number', 'default' => 1.0]]],
                    'C' => ['allOf' => [['$ref' => '#/components/schemas/A'], ['$ref' => '#/components/schemas/B']]],
                ],
                [],
            ],
            'requirement the parent already makes' => [
                [
                    'Animal' => ['type' => 'object', 'required' => ['id'], 'properties' => ['id' => ['type' => 'string']]],
                    'Pet' => ['allOf' => [['$ref' => '#/components/schemas/Animal'], ['required' => ['name'], 'properties' => ['name' => ['type' => 'string']]]]],
                    'Cat' => ['allOf' => [self::PET, ['required' => ['id', 'name', 'unknown'], 'properties' => ['lives' => []]]]],
                ],
                [],
            ],
            'requirement on a grandparent property' => [
                [
                    'Animal' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string'], 'tag' => ['type' => 'string']]],
                    'Pet' => ['allOf' => [['$ref' => '#/components/schemas/Animal'], ['properties' => ['name' => []]]]],
                    'Cat' => ['allOf' => [self::PET, ['required' => ['lives', 'id', 'name', 'tag'], 'properties' => ['age' => [], 'lives' => []]]]],
                ],
                [
                    "warning {$at}Cat: Required property \"id\" belongs to the parent App\\Dto\\Pet, where extending cannot make it required; use \"x-php-all-of: merge\" to require it.",
                    "warning {$at}Cat: Required property \"name\" belongs to the parent App\\Dto\\Pet, where extending cannot make it required; use \"x-php-all-of: merge\" to require it.",
                    "warning {$at}Cat: Required property \"tag\" belongs to the parent App\\Dto\\Pet, where extending cannot make it required; use \"x-php-all-of: merge\" to require it.",
                ],
            ],
            'discriminated parent in an inheritance loop' => [
                [
                    'A' => ['allOf' => [['$ref' => '#/components/schemas/B'], ['properties' => ['kind' => []]]], 'discriminator' => ['propertyName' => 'kind']],
                    'B' => ['allOf' => [['$ref' => '#/components/schemas/A'], ['properties' => ['b' => []]]]],
                ],
                ["error {$at}A: Class App\\Dto\\A extends itself through App\\Dto\\B."],
            ],
            'unmapped subclass of a listed base' => [
                [
                    'Pet' => ['properties' => ['kind' => ['type' => 'string']], 'oneOf' => [['$ref' => '#/components/schemas/Cat']], 'discriminator' => ['propertyName' => 'kind']],
                    'Cat' => ['type' => 'object', 'properties' => ['lives' => []]],
                    'Dog' => ['allOf' => [self::PET, ['properties' => ['bark' => []]]]],
                ],
                ["warning {$at}Dog: App\\Dto\\Dog extends the discriminated base App\\Dto\\Pet but is not one of its variants, so it gets no discriminator value."],
            ],
            'discriminator property no variant has' => [
                [
                    'Pet' => ['oneOf' => [['$ref' => '#/components/schemas/Cat'], ['$ref' => '#/components/schemas/Dog']], 'discriminator' => ['propertyName' => 'kind']],
                    'Cat' => ['type' => 'object', 'properties' => ['kind' => ['type' => 'string'], 'a' => []]],
                    'Dog' => ['type' => 'object', 'properties' => ['b' => []]],
                ],
                ["warning {$at}Dog: Variant App\\Dto\\Dog has no property \"kind\", which the discriminator reads."],
            ],
            'discriminated base listing with oneOf and anyOf' => [
                [
                    'Pet' => ['oneOf' => [['$ref' => '#/components/schemas/Cat']], 'anyOf' => [['$ref' => '#/components/schemas/Dog']], 'discriminator' => ['propertyName' => 'kind']],
                    'Cat' => ['type' => 'object', 'properties' => ['kind' => ['type' => 'string']]],
                    'Dog' => ['type' => 'object', 'properties' => ['kind' => ['type' => 'string']]],
                ],
                ["warning {$at}Pet: \"oneOf\" and \"anyOf\" together become one union, which admits more than the schema does."],
            ],
            'merged property with another default' => [
                [
                    'A' => ['type' => 'object', 'properties' => ['x' => ['type' => 'string', 'default' => 'a']]],
                    'B' => ['type' => 'object', 'properties' => ['x' => ['type' => 'string', 'default' => 'b']]],
                    'C' => ['allOf' => [['$ref' => '#/components/schemas/A'], ['$ref' => '#/components/schemas/B']]],
                ],
                ["error {$at}B/properties/x: Property \"x\" of App\\Dto\\C has another PHP name or default here than in an earlier allOf member."],
            ],
            'oneOf beside properties' => [
                ['Pet' => $pet + ['oneOf' => [['required' => ['name']]]]],
                ["warning {$at}Pet: \"oneOf\" and \"anyOf\" beside \"properties\" are not represented; the class keeps only its properties."],
            ],
        ];
    }

    public function testExtendsADiscriminatedBase(): void
    {
        $output = ModelFixture::build([
            'Pet' => ['oneOf' => [['$ref' => '#/components/schemas/Cat'], ['$ref' => '#/components/schemas/Dog']], 'discriminator' => ['propertyName' => 'kind']],
            'Cat' => ['type' => 'object', 'properties' => ['kind' => ['type' => 'string']]],
            'Dog' => ['type' => 'object', 'properties' => ['kind' => ['type' => 'string']]],
            'Tagged' => ['allOf' => [self::PET, ['properties' => ['tag' => ['type' => 'string']]]]],
        ]);

        self::assertSame(
            ['warning ' . self::AT . 'Tagged: App\\Dto\\Tagged extends the discriminated base App\\Dto\\Pet but is not one of its variants, so it gets no discriminator value.'],
            ModelFixture::messages($output),
        );
        self::assertSame('final extends App\Dto\Pet', ModelFixture::hierarchy($output)['App\Dto\Tagged']);
    }

    public function testIgnoresAnUnresolvedMember(): void
    {
        $output = ModelFixture::build(
            ['Cat' => ['allOf' => [['$ref' => '#/components/schemas/Missing'], ['properties' => ['lives' => ['type' => 'integer']]]]]],
            [],
            ['*'],
            AllOfStrategy::EXTENDS,
            false,
        );

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(['App\Dto\Cat' => ['lives: int|null']], ModelFixture::classes($output));
    }

    public function testHoistsInlineObjectsOfMembers(): void
    {
        // Kitten comes first, so it reaches the inline objects of Pet and Cat before their own classes do.
        $output = ModelFixture::build([
            'Kitten' => ['allOf' => [['$ref' => '#/components/schemas/Cat'], self::PET], 'x-php-all-of' => 'merge'],
            'Pet' => ['type' => 'object', 'properties' => ['owner' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]]]],
            'Cat' => ['allOf' => [self::PET, ['properties' => ['toy' => ['type' => 'object', 'properties' => ['kind' => ['type' => 'string']]]]]]],
        ]);

        self::assertSame([], ModelFixture::messages($output));
        $classes = ModelFixture::classes($output);
        self::assertSame(['toy: App\Dto\CatToy|null'], $classes['App\Dto\Cat']);
        self::assertSame(['owner: App\Dto\PetOwner|null', 'toy: App\Dto\CatToy|null'], $classes['App\Dto\Kitten']);
        self::assertArrayNotHasKey('App\Dto\KittenOwner', $classes);
    }

    public function testDeclaresAnInlineComposition(): void
    {
        $output = ModelFixture::build([
            'Pet' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
            'Owner' => ['type' => 'object', 'properties' => ['pet' => ['allOf' => [self::PET, ['properties' => ['since' => ['type' => 'string']]]]]]],
        ]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(['pet: App\Dto\OwnerPet|null'], ModelFixture::classes($output)['App\Dto\Owner']);
        self::assertSame('final extends App\Dto\Pet', ModelFixture::hierarchy($output)['App\Dto\OwnerPet']);
    }

    public function testTurnsADiscriminatedUnionIntoAnAbstractBase(): void
    {
        $output = ModelFixture::build([
            'Pet' => [
                'oneOf' => [['$ref' => '#/components/schemas/Cat'], ['$ref' => '#/components/schemas/Dog']],
                'discriminator' => ['propertyName' => 'petType', 'mapping' => ['cat' => '#/components/schemas/Cat']],
            ],
            'Cat' => ['type' => 'object', 'required' => ['petType', 'name'], 'properties' => [
                'petType' => ['type' => 'string'], 'name' => ['type' => 'string'], 'lives' => ['type' => 'integer'],
            ]],
            'Dog' => ['type' => 'object', 'required' => ['petType', 'name'], 'properties' => [
                'name' => ['type' => 'string'], 'bark' => ['type' => 'boolean'], 'petType' => ['type' => 'string'],
            ]],
        ]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(
            ['App\Dto\Pet' => ['petType: string', 'name: string'], 'App\Dto\Cat' => ['lives: int|null'], 'App\Dto\Dog' => ['bark: bool|null']],
            ModelFixture::classes($output),
        );
        self::assertSame(
            [
                'App\Dto\Pet' => 'abstract by petType {cat: App\Dto\Cat, Dog: App\Dto\Dog}',
                'App\Dto\Cat' => 'final extends App\Dto\Pet',
                'App\Dto\Dog' => 'final extends App\Dto\Pet',
            ],
            ModelFixture::hierarchy($output),
        );
    }

    public function testKeepsPropertiesThatDifferBetweenVariants(): void
    {
        $output = ModelFixture::build([
            'Pet' => ['anyOf' => [['$ref' => '#/components/schemas/Cat'], ['$ref' => '#/components/schemas/Dog']], 'discriminator' => ['propertyName' => 'kind']],
            'Cat' => ['type' => 'object', 'required' => ['name'], 'properties' => [
                'kind' => ['type' => 'string'], 'name' => ['type' => 'string'], 'size' => ['type' => 'string', 'default' => 's'], 'tag' => ['type' => 'string'],
            ]],
            'Dog' => ['type' => 'object', 'properties' => [
                'kind' => ['type' => 'string'], 'name' => ['type' => 'string'], 'size' => ['type' => 'string', 'default' => 'l'], 'tag' => ['type' => 'string', 'x-php-name' => 'label'],
            ]],
        ]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(
            [
                'App\Dto\Pet' => ['kind: string|null'],
                'App\Dto\Cat' => ['name: string', 'size: string|null', 'tag: string|null'],
                'App\Dto\Dog' => ['name: string|null', 'size: string|null', 'tag: string|null'],
            ],
            ModelFixture::classes($output),
        );
    }

    public function testMovesSharedPropertiesNextToTheOwnPropertiesOfTheBase(): void
    {
        $output = ModelFixture::build([
            'Pet' => [
                'properties' => ['kind' => ['type' => 'string']],
                'oneOf' => [['$ref' => '#/components/schemas/Cat'], ['$ref' => '#/components/schemas/Dog']],
                'discriminator' => ['propertyName' => 'kind'],
            ],
            'Cat' => ['type' => 'object', 'properties' => ['kind' => ['type' => 'string'], 'name' => ['type' => 'string'], 'a' => ['type' => 'integer']]],
            'Dog' => ['type' => 'object', 'properties' => ['kind' => ['type' => 'string'], 'b' => ['type' => 'boolean'], 'name' => ['type' => 'string']]],
        ]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(
            ['App\Dto\Pet' => ['kind: string|null', 'name: string|null'], 'App\Dto\Cat' => ['a: int|null'], 'App\Dto\Dog' => ['b: bool|null']],
            ModelFixture::classes($output),
        );
    }

    public function testSharesNothingWithAVariantThatHasAnotherParent(): void
    {
        $output = ModelFixture::build([
            'Pet' => ['oneOf' => [['$ref' => '#/components/schemas/Cat'], ['$ref' => '#/components/schemas/Dog']], 'discriminator' => ['propertyName' => 'kind']],
            'Animal' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']]],
            'Cat' => ['allOf' => [['$ref' => '#/components/schemas/Animal'], ['properties' => ['kind' => ['type' => 'string']]]]],
            'Dog' => ['type' => 'object', 'properties' => ['kind' => ['type' => 'string']]],
        ]);

        self::assertCount(1, ModelFixture::messages($output));
        self::assertSame([], ModelFixture::classes($output)['App\Dto\Pet']);
        self::assertSame(['kind: string|null'], ModelFixture::classes($output)['App\Dto\Dog']);
    }

    public function testKeepsTheVariantsAroundOneItCannotUse(): void
    {
        $at = self::AT;
        $output = ModelFixture::build([
            'Pet' => [
                'oneOf' => [
                    ['type' => 'object', 'properties' => ['a' => []]],
                    ['$ref' => '#/components/schemas/Missing'],
                    ['$ref' => '#/components/schemas/Name'],
                    ['$ref' => '#/components/schemas/Cat'],
                ],
                'discriminator' => ['propertyName' => 'kind', 'mapping' => ['dog' => 'Dog', 'gone' => 'Gone', 'cat' => 'Cat']],
            ],
            'Cat' => ['type' => 'object', 'properties' => ['kind' => ['type' => 'string']]],
            'Dog' => ['type' => 'object', 'properties' => ['kind' => ['type' => 'string']]],
            'Name' => ['type' => 'string'],
        ], [], ['*'], AllOfStrategy::EXTENDS, false);

        $messages = ModelFixture::messages($output);
        self::assertContains("error {$at}Pet/oneOf/0: A variant of a discriminated union must be a \$ref to an object schema.", $messages);
        self::assertContains("error {$at}Pet/discriminator/mapping/dog: Discriminator value \"dog\" maps to a schema that is not one of the variants.", $messages);
        self::assertContains("error {$at}Pet/oneOf/2: A variant of a discriminated union must be a \$ref to an object schema.", $messages);
        self::assertCount(3, $messages);
        self::assertSame('abstract by kind {cat: App\Dto\Cat}', ModelFixture::hierarchy($output)['App\Dto\Pet']);
    }

    public function testAcceptsVariantsThatExtendTheBaseThemselves(): void
    {
        $output = ModelFixture::build([
            'Pet' => [
                'properties' => ['petType' => ['type' => 'string']],
                'required' => ['petType'],
                'oneOf' => [['$ref' => '#/components/schemas/Cat']],
                'discriminator' => ['propertyName' => 'petType'],
            ],
            'Cat' => ['allOf' => [self::PET, ['properties' => ['lives' => ['type' => 'integer']]]]],
        ]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(['App\Dto\Pet' => ['petType: string'], 'App\Dto\Cat' => ['lives: int|null']], ModelFixture::classes($output));
        self::assertSame(
            ['App\Dto\Pet' => 'abstract by petType {Cat: App\Dto\Cat}', 'App\Dto\Cat' => 'final extends App\Dto\Pet'],
            ModelFixture::hierarchy($output),
        );
    }

    /**
     * @dataProvider variantProblems
     *
     * @param array<string, array<array-key, mixed>> $schemas
     * @param list<string> $expected
     */
    public function testReportsVariantProblems(array $schemas, array $expected): void
    {
        self::assertSame($expected, ModelFixture::messages(ModelFixture::build($schemas)));
    }

    /**
     * @return array<string, array{array<string, array<array-key, mixed>>, list<string>}>
     */
    public static function variantProblems(): array
    {
        $at = self::AT;
        $cat = ['type' => 'object', 'properties' => ['kind' => ['type' => 'string']]];
        $discriminator = ['propertyName' => 'kind'];
        $catRef = ['$ref' => '#/components/schemas/Cat'];

        return [
            'inline variant' => [
                ['Pet' => ['oneOf' => [$catRef, ['type' => 'object', 'properties' => ['a' => []]]], 'discriminator' => $discriminator], 'Cat' => $cat],
                ["error {$at}Pet/oneOf/1: A variant of a discriminated union must be a \$ref to an object schema."],
            ],
            'variant that is not a class' => [
                ['Pet' => ['oneOf' => [$catRef, ['$ref' => '#/components/schemas/Name']], 'discriminator' => $discriminator], 'Cat' => $cat, 'Name' => ['type' => 'string']],
                ["error {$at}Pet/oneOf/1: A variant of a discriminated union must be a \$ref to an object schema."],
            ],
            'mapping outside the variants' => [
                [
                    'Pet' => ['oneOf' => [$catRef], 'discriminator' => ['propertyName' => 'kind', 'mapping' => ['dog' => 'Dog']]],
                    'Cat' => $cat,
                    'Dog' => $cat,
                ],
                ["error {$at}Pet/discriminator/mapping/dog: Discriminator value \"dog\" maps to a schema that is not one of the variants."],
            ],
            'variant with another parent' => [
                [
                    'Pet' => ['oneOf' => [$catRef], 'discriminator' => $discriminator],
                    'Animal' => $cat,
                    'Cat' => ['allOf' => [['$ref' => '#/components/schemas/Animal'], ['properties' => ['lives' => []]]]],
                ],
                ["error {$at}Cat: App\\Dto\\Cat already extends App\\Dto\\Animal, so it cannot also be a variant of App\\Dto\\Pet."],
            ],
            'mapping to a schema that is no class' => [
                ['Pet' => ['oneOf' => [$catRef], 'discriminator' => ['propertyName' => 'kind', 'mapping' => ['n' => 'Name']]], 'Cat' => $cat, 'Name' => ['type' => 'string']],
                ["error {$at}Pet/discriminator/mapping/n: Discriminator value \"n\" maps to a schema that is not one of the variants."],
            ],
            'variant that is the base' => [
                ['Pet' => ['oneOf' => [$catRef, ['$ref' => '#/components/schemas/Pet']], 'discriminator' => $discriminator], 'Cat' => $cat],
                ["error {$at}Pet: App\\Dto\\Pet lists itself among its variants."],
            ],
            'variant in an inheritance loop' => [
                [
                    'Pet' => ['oneOf' => [$catRef, ['$ref' => '#/components/schemas/Dog']], 'discriminator' => $discriminator],
                    'Cat' => ['allOf' => [['$ref' => '#/components/schemas/Dog'], ['properties' => ['c' => []]]]],
                    'Dog' => ['allOf' => [$catRef, ['properties' => ['d' => []]]]],
                ],
                [
                    "error {$at}Cat: App\\Dto\\Cat already extends App\\Dto\\Dog, so it cannot also be a variant of App\\Dto\\Pet.",
                    "error {$at}Dog: App\\Dto\\Dog already extends App\\Dto\\Cat, so it cannot also be a variant of App\\Dto\\Pet.",
                    "error {$at}Cat: Class App\\Dto\\Cat extends itself through App\\Dto\\Dog.",
                ],
            ],
            'variant of two unions' => [
                [
                    'Pet' => ['oneOf' => [$catRef], 'discriminator' => $discriminator],
                    'Animal' => ['oneOf' => [$catRef], 'discriminator' => $discriminator],
                    'Cat' => $cat,
                ],
                ["error {$at}Cat: App\\Dto\\Cat already extends App\\Dto\\Pet, so it cannot also be a variant of App\\Dto\\Animal."],
            ],
        ];
    }

    public function testLeavesAUnionWithoutDiscriminatorAsAType(): void
    {
        $output = ModelFixture::build([
            'Owner' => ['type' => 'object', 'properties' => ['pet' => ['$ref' => '#/components/schemas/Pet']]],
            'Pet' => ['oneOf' => [['$ref' => '#/components/schemas/Cat'], ['type' => 'string']]],
            'Cat' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
        ]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(['App\Dto\Owner' => ['pet: App\Dto\Cat|string|null'], 'App\Dto\Cat' => ['name: string|null']], ModelFixture::classes($output));
    }

    public function testFindsRequiredCyclesThroughInheritedProperties(): void
    {
        $at = self::AT;
        $output = ModelFixture::build([
            'Base' => ['type' => 'object', 'required' => ['next'], 'properties' => ['next' => ['$ref' => '#/components/schemas/Child']]],
            'Child' => ['allOf' => [['$ref' => '#/components/schemas/Base'], ['properties' => ['x' => []]]]],
        ]);

        self::assertSame(
            ["warning {$at}Base/properties/next: Required property \"next\" of App\\Dto\\Child leads back to it through required properties, so no instance can ever be constructed."],
            ModelFixture::messages($output),
        );
    }

    /**
     * @return list<string> wire names of the inherited properties, root first
     */
    private function inherited(Output $output, string $fqcn): array
    {
        foreach ($output->classes() as $class) {
            if ($class->model()->name()->fqcn() === $fqcn) {
                return array_map(static fn (PropertyModel $property): string => $property->wireName(), $output->inheritedProperties($class->model()));
            }
        }

        self::fail('No class ' . $fqcn);
    }

    public function testKeepsAParentRequirementOnlyMemberAsASubclass(): void
    {
        $output = ModelFixture::build([
            'Pet' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
            'Cat' => ['allOf' => [self::PET, ['required' => ['name']]]],
        ]);

        self::assertCount(1, ModelFixture::messages($output));
        self::assertSame('final extends App\Dto\Pet', ModelFixture::hierarchy($output)['App\Dto\Cat']);
    }

    public function testDropsAPropertySkippedInAnyMergedMember(): void
    {
        $output = ModelFixture::build([
            'A' => ['type' => 'object', 'properties' => ['x' => ['type' => 'string', 'x-php-skip' => true], 'z' => ['x-php-skip' => true]]],
            'B' => ['type' => 'object', 'properties' => ['x' => ['type' => 'integer'], 'y' => ['type' => 'string'], 'z' => ['type' => 'string']]],
            'C' => ['allOf' => [['$ref' => '#/components/schemas/A'], ['$ref' => '#/components/schemas/B']]],
        ]);

        self::assertSame(['y: string|null'], ModelFixture::classes($output)['App\Dto\C']);
    }

    public function testExtendsAClassBehindAReferenceWrapper(): void
    {
        $output = ModelFixture::build([
            'Pet' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
            'AnyPet' => ['allOf' => [['description' => 'Any pet.'], self::PET]],
            'Cat' => ['allOf' => [['$ref' => '#/components/schemas/AnyPet'], ['properties' => ['lives' => ['type' => 'integer']]]]],
            'Other' => ['type' => 'object', 'properties' => ['name' => ['type' => 'integer']]],
            'Zoo' => [
                'oneOf' => [['$ref' => '#/components/schemas/AnyPet'], ['$ref' => '#/components/schemas/Other']],
                'discriminator' => ['propertyName' => 'name'],
            ],
        ]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame('final extends App\Dto\Pet', ModelFixture::hierarchy($output)['App\Dto\Cat']);
        self::assertSame(['lives: int|null'], ModelFixture::classes($output)['App\Dto\Cat']);
        self::assertSame('abstract by name {AnyPet: App\Dto\Pet, Other: App\Dto\Other}', ModelFixture::hierarchy($output)['App\Dto\Zoo']);
    }

    public function testCountsAVariantListedTwiceOnce(): void
    {
        $at = self::AT;
        $output = ModelFixture::build([
            'Pet' => [
                'oneOf' => [['$ref' => '#/components/schemas/Cat'], ['$ref' => '#/components/schemas/Cat'], ['$ref' => '#/components/schemas/Dog']],
                'discriminator' => ['propertyName' => 'kind'],
            ],
            'Cat' => ['type' => 'object', 'properties' => ['kind' => ['type' => 'string'], 'lives' => ['type' => 'integer']]],
            'Dog' => ['type' => 'object', 'properties' => ['kind' => ['type' => 'string']]],
        ]);

        self::assertSame(["warning {$at}Pet/oneOf/1: Variant App\\Dto\\Cat is listed twice."], ModelFixture::messages($output));
        self::assertSame(['App\Dto\Pet' => ['kind: string|null'], 'App\Dto\Cat' => ['lives: int|null'], 'App\Dto\Dog' => []], ModelFixture::classes($output));
    }

    public function testWarnsAboutAVariantTheMappingLeavesWithoutValue(): void
    {
        $at = self::AT;
        $output = ModelFixture::build([
            'Pet' => [
                'oneOf' => [['$ref' => '#/components/schemas/Cat'], ['$ref' => '#/components/schemas/Dog'], ['$ref' => '#/components/schemas/Bird']],
                'discriminator' => ['propertyName' => 'kind', 'mapping' => ['Cat' => 'Dog']],
            ],
            'Cat' => ['type' => 'object', 'properties' => ['kind' => ['type' => 'string']]],
            'Dog' => ['type' => 'object', 'properties' => ['kind' => ['type' => 'string']]],
            'Bird' => ['type' => 'object', 'properties' => ['kind' => ['type' => 'string']]],
        ]);

        self::assertSame(
            ["warning {$at}Pet/discriminator: Variant App\\Dto\\Cat gets no discriminator value: the mapping gives \"Cat\" to another schema."],
            ModelFixture::messages($output),
        );
        self::assertSame('abstract by kind {Cat: App\Dto\Dog, Bird: App\Dto\Bird}', ModelFixture::hierarchy($output)['App\Dto\Pet']);
    }

    public function testSharesPropertiesOfNestedBasesFromTheLeavesUp(): void
    {
        $k = ['type' => 'object', 'required' => ['k'], 'properties' => ['k' => ['type' => 'string']]];
        $outer = ['oneOf' => [['$ref' => '#/components/schemas/B1'], ['$ref' => '#/components/schemas/X']], 'discriminator' => ['propertyName' => 'k']];
        $inner = ['oneOf' => [['$ref' => '#/components/schemas/Y'], ['$ref' => '#/components/schemas/Z']], 'discriminator' => ['propertyName' => 'k']];
        $first = ModelFixture::build(['B0' => $outer, 'B1' => $inner, 'X' => $k, 'Y' => $k, 'Z' => $k]);
        $second = ModelFixture::build(['B1' => $inner, 'B0' => $outer, 'X' => $k, 'Y' => $k, 'Z' => $k]);

        self::assertSame([], ModelFixture::messages($first));
        $expected = ['App\Dto\B0' => ['k: string'], 'App\Dto\B1' => [], 'App\Dto\X' => [], 'App\Dto\Y' => [], 'App\Dto\Z' => []];
        self::assertEquals($expected, ModelFixture::classes($first));
        self::assertEquals($expected, ModelFixture::classes($second));
        self::assertSame('abstract extends App\Dto\B0 by k {Y: App\Dto\Y, Z: App\Dto\Z}', ModelFixture::hierarchy($first)['App\Dto\B1']);
    }

    public function testTurnsADiscriminatedAllOfParentIntoAnAbstractBase(): void
    {
        $output = ModelFixture::build([
            'Pet' => [
                'type' => 'object',
                'required' => ['petType'],
                'properties' => ['petType' => ['type' => 'string']],
                'discriminator' => ['propertyName' => 'petType', 'mapping' => ['cat' => '#/components/schemas/Cat']],
            ],
            'Cat' => ['allOf' => [self::PET, ['properties' => ['name' => ['type' => 'string'], 'lives' => ['type' => 'integer']]]]],
            'Dog' => ['allOf' => [self::PET, ['properties' => ['name' => ['type' => 'string']]]]],
            'Other' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
        ]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(
            [
                'App\Dto\Pet' => 'abstract by petType {cat: App\Dto\Cat, Dog: App\Dto\Dog}',
                'App\Dto\Cat' => 'final extends App\Dto\Pet',
                'App\Dto\Dog' => 'final extends App\Dto\Pet',
                'App\Dto\Other' => 'final',
            ],
            ModelFixture::hierarchy($output),
        );
        self::assertSame(['petType: string'], ModelFixture::classes($output)['App\Dto\Pet']);
        self::assertSame(['name: string|null'], ModelFixture::classes($output)['App\Dto\Dog']);
    }

    public function testRejectsAMappingOfAnAllOfParentToAnotherSchema(): void
    {
        $at = self::AT;
        $output = ModelFixture::build([
            'Pet' => ['type' => 'object', 'properties' => ['petType' => ['type' => 'string']], 'discriminator' => ['propertyName' => 'petType', 'mapping' => ['x' => 'Other']]],
            'Cat' => ['allOf' => [self::PET, ['properties' => ['lives' => ['type' => 'integer']]]]],
            'Other' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
        ]);

        self::assertSame(["error {$at}Pet/discriminator/mapping/x: Discriminator value \"x\" maps to a schema that is not one of the variants."], ModelFixture::messages($output));
    }

    public function testKeepsBuildingAfterASchemaThatCombinesAllOfAndOneOf(): void
    {
        $output = ModelFixture::build([
            'P' => ['allOf' => [self::PET], 'oneOf' => [self::PET]],
            'Pet' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
        ]);

        self::assertCount(1, ModelFixture::messages($output));
        self::assertSame(['App\Dto\Pet'], array_keys(ModelFixture::classes($output)));
    }

    public function testLeavesInlineSubclassesOutOfTheDiscriminatorMapping(): void
    {
        $vehicle = ['$ref' => '#/components/schemas/Vehicle'];
        $output = ModelFixture::build([
            'Vehicle' => ['type' => 'object', 'properties' => ['kind' => ['type' => 'string']], 'discriminator' => ['propertyName' => 'kind']],
            'Car' => ['allOf' => [$vehicle, ['properties' => ['doors' => ['type' => 'integer']]]]],
            'Garage' => ['type' => 'object', 'properties' => ['spare' => ['allOf' => [$vehicle, ['properties' => ['wheels' => ['type' => 'integer']]]]]]],
        ]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame('abstract by kind {Car: App\Dto\Car}', ModelFixture::hierarchy($output)['App\Dto\Vehicle']);
        self::assertSame('final extends App\Dto\Vehicle', ModelFixture::hierarchy($output)['App\Dto\GarageSpare']);
    }

    public function testAcceptsAnExplicitTypeForACompositionItCannotGenerate(): void
    {
        $output = ModelFixture::build([
            'A' => ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]],
            'Ext' => [
                'x-php-type' => 'App\\Custom\\Shape',
                'allOf' => [['$ref' => '#/components/schemas/A']],
                'oneOf' => [['type' => 'object', 'properties' => ['b' => ['type' => 'string']]]],
            ],
            'Scalar' => ['type' => 'string', 'allOf' => [['minLength' => 1]], 'anyOf' => [['pattern' => 'a'], ['pattern' => 'b']]],
            'H' => ['type' => 'object', 'properties' => ['e' => ['$ref' => '#/components/schemas/Ext'], 's' => ['$ref' => '#/components/schemas/Scalar']]],
        ]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(['e: App\Custom\Shape|null', 's: string|null'], ModelFixture::classes($output)['App\Dto\H']);
    }

    public function testMapsEveryNamedDescendantOfADiscriminatedAllOfParent(): void
    {
        $output = ModelFixture::build([
            'Pet' => ['type' => 'object', 'properties' => ['petType' => ['type' => 'string']], 'discriminator' => ['propertyName' => 'petType']],
            'Cat' => ['allOf' => [self::PET, ['properties' => ['lives' => ['type' => 'integer']]]]],
            'Lion' => ['allOf' => [['$ref' => '#/components/schemas/Cat'], ['properties' => ['mane' => ['type' => 'boolean']]]]],
            'Dog' => ['allOf' => [self::PET, ['properties' => ['bark' => ['type' => 'boolean']]]]],
            'Puppy' => ['allOf' => [['$ref' => '#/components/schemas/Dog'], ['properties' => ['age' => ['type' => 'integer']]]]],
        ]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(
            [
                'App\Dto\Pet' => 'abstract by petType {Cat: App\Dto\Cat, Dog: App\Dto\Dog, Lion: App\Dto\Lion, Puppy: App\Dto\Puppy}',
                'App\Dto\Cat' => 'open extends App\Dto\Pet',
                'App\Dto\Lion' => 'final extends App\Dto\Cat',
                'App\Dto\Dog' => 'open extends App\Dto\Pet',
                'App\Dto\Puppy' => 'final extends App\Dto\Dog',
            ],
            ModelFixture::hierarchy($output),
        );
    }

    public function testAdoptsListedVariantsAfterABaseWithSubclasses(): void
    {
        $output = ModelFixture::build([
            'Vehicle' => ['type' => 'object', 'properties' => ['kind' => ['type' => 'string']], 'discriminator' => ['propertyName' => 'kind']],
            'Car' => ['allOf' => [['$ref' => '#/components/schemas/Vehicle'], ['properties' => ['doors' => ['type' => 'integer']]]]],
            'Pet' => ['oneOf' => [['$ref' => '#/components/schemas/Cat']], 'discriminator' => ['propertyName' => 'kind']],
            'Cat' => ['type' => 'object', 'properties' => ['kind' => ['type' => 'string']]],
        ]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame('final extends App\Dto\Pet', ModelFixture::hierarchy($output)['App\Dto\Cat']);
    }

    public function testKeepsADiscriminatedParentOutOfItsOwnMappingInALoop(): void
    {
        $output = ModelFixture::build([
            'A' => ['allOf' => [['$ref' => '#/components/schemas/B'], ['properties' => ['kind' => []]]], 'discriminator' => ['propertyName' => 'kind']],
            'B' => ['allOf' => [['$ref' => '#/components/schemas/A'], ['properties' => ['b' => []]]]],
        ]);

        self::assertSame('abstract by kind {B: App\\Dto\\B}', ModelFixture::hierarchy($output)['App\\Dto\\A']);
    }

    public function testChecksTheDiscriminatorOfEveryBaseAfterOneWithoutVariants(): void
    {
        $at = self::AT;
        $output = ModelFixture::build([
            'Empty' => ['oneOf' => [['type' => 'object', 'properties' => ['a' => []]]], 'discriminator' => ['propertyName' => 'kind']],
            'Pet' => ['oneOf' => [['$ref' => '#/components/schemas/Cat']], 'discriminator' => ['propertyName' => 'kind']],
            'Cat' => ['type' => 'object', 'properties' => ['lives' => []]],
        ]);

        self::assertSame(
            [
                "error {$at}Empty/oneOf/0: A variant of a discriminated union must be a \$ref to an object schema.",
                "warning {$at}Cat: Variant App\\Dto\\Cat has no property \"kind\", which the discriminator reads.",
            ],
            ModelFixture::messages($output),
        );
    }
}
