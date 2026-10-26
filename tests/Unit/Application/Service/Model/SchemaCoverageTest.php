<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Application\Service\Model;

use MSSTC4PHP\DtoGenerator\Domain\Target\AllOfStrategy;
use MSSTC4PHP\DtoGenerator\Tests\Support\ModelFixture;
use PHPUnit\Framework\TestCase;

/**
 * Schemas the builder used to reject or type as mixed: const, mixed enums, inline members of unions and aliases.
 */
final class SchemaCoverageTest extends TestCase
{
    private const AT = 'error /project/api/openapi.yaml#/components/schemas/';

    public function testGivesAConstItsLiteralType(): void
    {
        $output = ModelFixture::build(['C' => ['type' => 'object', 'required' => ['s', 'i', 'b'], 'properties' => [
            's' => ['const' => 'card'],
            'i' => ['const' => 5],
            'b' => ['type' => 'boolean', 'const' => true],
            'f' => ['const' => 1.5],
            'u' => ['const' => "it's"],
            'n' => ['type' => 'number', 'const' => 2],
        ]]]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(["s: 'card'", 'i: 5', 'b: true', 'f: float|null', 'u: string|null', 'n: float|null'], ModelFixture::classes($output)['App\Dto\C']);
    }

    public function testKeepsTheDeclaredTypeOfAConstThatContradictsIt(): void
    {
        $output = ModelFixture::build(['C' => ['type' => 'object', 'properties' => [
            's' => ['type' => 'string', 'const' => 5],
            'half' => ['type' => 'integer', 'const' => 2.5],
        ]]]);

        $warning = 'warning /project/api/openapi.yaml#/components/schemas/C/properties/%s/const: "const" is not of the declared type; the property keeps its declared type.';
        self::assertSame([sprintf($warning, 's'), sprintf($warning, 'half')], ModelFixture::messages($output));
        self::assertSame(['s: string|null', 'half: int|null'], ModelFixture::classes($output)['App\Dto\C']);
    }

    public function testTypesNumericConstsAsThePropertyHoldsThem(): void
    {
        $output = ModelFixture::build(['C' => ['type' => 'object', 'properties' => [
            'whole' => ['type' => 'integer', 'const' => 2.0],
            'real' => ['type' => 'number', 'const' => 2.0],
            'ratio' => ['type' => 'number', 'const' => 1],
            'bare' => ['const' => 2.0],
        ]]]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(['whole: 2|null', 'real: float|null', 'ratio: float|null', 'bare: 2|null'], ModelFixture::classes($output)['App\Dto\C']);
    }

    public function testKeepsTheFormatTypeOfAConst(): void
    {
        $output = ModelFixture::build(['C' => ['type' => 'object', 'properties' => [
            'born' => ['type' => 'string', 'format' => 'date-time', 'const' => '2020-01-01T00:00:00Z'],
            'host' => ['type' => 'string', 'format' => 'hostname', 'const' => 'example.org'],
        ]]]);

        self::assertSame(['born: DateTimeImmutable|null', "host: 'example.org'|null"], ModelFixture::classes($output)['App\Dto\C']);
    }

    public function testRejectsDefaultsTheConstOrMixedEnumForbids(): void
    {
        $output = ModelFixture::build(['C' => ['type' => 'object', 'properties' => [
            'u' => ['const' => "it's", 'default' => 'x'],
            'f' => ['const' => 1.5, 'default' => 2.5],
            'same' => ['type' => 'number', 'const' => 2, 'default' => 2.0],
            'm' => ['enum' => ["it's", 1], 'default' => 'zz'],
        ]]]);

        self::assertSame(
            [
                self::AT . 'C/properties/u/default: Default "x" is not the "const" value "it\'s"; null is used instead.',
                self::AT . 'C/properties/f/default: Default 2.5 is not the "const" value 1.5; null is used instead.',
                'warning /project/api/openapi.yaml#/components/schemas/C/properties/m/enum: The enum mixes strings and integers, which no PHP enum can back; the property takes either.',
                self::AT . 'C/properties/m/default: Default "zz" is not one of the "enum" values; null is used instead.',
            ],
            ModelFixture::messages($output),
        );
    }

    public function testRejectsADefaultOtherThanTheConst(): void
    {
        $output = ModelFixture::build(['C' => ['type' => 'object', 'properties' => ['i' => ['const' => 5, 'default' => 6]]]]);

        self::assertSame([self::AT . 'C/properties/i/default: Default 6 is not the "const" value 5; null is used instead.'], ModelFixture::messages($output));
    }

    public function testGivesAMixedEnumAUnionOfItsLiterals(): void
    {
        $output = ModelFixture::build(['C' => ['type' => 'object', 'required' => ['m'], 'properties' => [
            'm' => ['enum' => ['active', 2, 'blocked']],
            'n' => ['enum' => ['a', 1, null]],
            'o' => ['type' => ['string', 'integer'], 'enum' => ["it's", 1, 1]],
        ]]]);

        $warning = 'warning /project/api/openapi.yaml#/components/schemas/C/properties/%s/enum: The enum mixes strings and integers, which no PHP enum can back; the property takes either.';
        self::assertSame([sprintf($warning, 'm'), sprintf($warning, 'n'), sprintf($warning, 'o')], ModelFixture::messages($output));
        self::assertSame(["m: 'active'|'blocked'|2", "n: 'a'|1|null", 'o: string|1|null'], ModelFixture::classes($output)['App\Dto\C']);
        self::assertSame([], ModelFixture::enums($output));
    }

    public function testRejectsAMixedEnumWhoseTypeLeavesOneKindOut(): void
    {
        $output = ModelFixture::build(['C' => ['type' => 'object', 'properties' => ['m' => ['type' => 'string', 'enum' => ['a', 1]]]]]);

        self::assertSame(
            [self::AT . 'C/properties/m/type: "type" does not match the enum values, which are strings and integers.'],
            ModelFixture::messages($output),
        );
    }

    public function testHoistsInlineMembersOfAPropertyUnion(): void
    {
        $output = ModelFixture::build(['Holder' => ['type' => 'object', 'properties' => ['either' => ['oneOf' => [
            ['type' => 'object', 'title' => 'by id', 'properties' => ['id' => ['type' => 'integer']]],
            ['type' => 'object', 'properties' => ['code' => ['type' => 'string']]],
            ['type' => 'string'],
        ]]]]]);

        self::assertSame([], ModelFixture::messages($output));
        $classes = ModelFixture::classes($output);
        self::assertSame(['either: App\Dto\ById|App\Dto\HolderEitherOption2|string|null'], $classes['App\Dto\Holder']);
        self::assertSame(['id: int|null'], $classes['App\Dto\ById']);
        self::assertSame(['code: string|null'], $classes['App\Dto\HolderEitherOption2']);
    }

    public function testNumbersAnyOfMembersAfterOneOfMembers(): void
    {
        $output = ModelFixture::build(['Holder' => ['type' => 'object', 'properties' => ['either' => [
            'oneOf' => [['type' => 'string']],
            'anyOf' => [['type' => 'object', 'properties' => ['a' => ['type' => 'string']]]],
        ]]]]);

        self::assertArrayHasKey('App\Dto\HolderEitherOption2', ModelFixture::classes($output));
    }

    public function testHoistsUnionMembersInsideItems(): void
    {
        $output = ModelFixture::build(['Holder' => ['type' => 'object', 'properties' => ['list' => ['type' => 'array', 'items' => ['anyOf' => [
            ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]],
            ['type' => 'object', 'properties' => ['b' => ['type' => 'string']]],
        ]]]]]]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(['list: list<App\Dto\HolderListItemOption1|App\Dto\HolderListItemOption2>|null'], ModelFixture::classes($output)['App\Dto\Holder']);
    }

    public function testHoistsInlineEnumMembers(): void
    {
        $output = ModelFixture::build(['Holder' => ['type' => 'object', 'properties' => ['either' => ['oneOf' => [
            ['type' => 'string', 'enum' => ['a', 'b']],
            ['type' => 'integer'],
        ]]]]]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(['App\Dto\HolderEitherOption1'], ModelFixture::enums($output));
    }

    public function testNamesNestedInlineObjectsAfterTheHoistedMember(): void
    {
        $output = ModelFixture::build(['Holder' => ['type' => 'object', 'properties' => ['either' => ['oneOf' => [
            ['type' => 'object', 'properties' => ['address' => ['type' => 'object', 'properties' => ['city' => ['type' => 'string']]]]],
            ['type' => 'string'],
        ]]]]]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertArrayHasKey('App\Dto\HolderEitherOption1Address', ModelFixture::classes($output));
    }

    public function testTakesXPhpClassNameOnAMember(): void
    {
        $output = ModelFixture::build(['Holder' => ['type' => 'object', 'properties' => ['either' => ['oneOf' => [
            ['type' => 'object', 'title' => 'ignored', 'x-php-class-name' => 'Chosen', 'properties' => ['a' => ['type' => 'string']]],
            ['type' => 'string'],
        ]]]]]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertArrayHasKey('App\Dto\Chosen', ModelFixture::classes($output));
    }

    public function testNamesAMemberWhoseTitleCollidesAfterItsPlaceQuietly(): void
    {
        $output = ModelFixture::build([
            'User' => ['type' => 'object', 'properties' => ['n' => ['type' => 'string']]],
            'Holder' => ['type' => 'object', 'properties' => ['either' => ['oneOf' => [
                ['type' => 'object', 'title' => 'User', 'properties' => ['id' => ['type' => 'integer']]],
                ['type' => 'string'],
            ]]]],
        ]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertArrayHasKey('App\Dto\HolderEitherOption1', ModelFixture::classes($output));
    }

    public function testReportsAMemberWhoseTitleAndPlaceAreBothTaken(): void
    {
        $output = ModelFixture::build([
            'User' => ['type' => 'object', 'properties' => ['n' => ['type' => 'string']]],
            'HolderEitherOption1' => ['type' => 'object', 'properties' => ['m' => ['type' => 'string']]],
            'Holder' => ['type' => 'object', 'properties' => ['either' => ['oneOf' => [
                ['type' => 'object', 'title' => 'User', 'properties' => ['id' => ['type' => 'integer']]],
                ['type' => 'string'],
            ]]]],
        ]);

        self::assertSame(
            [str_replace('error ', 'warning ', self::AT) . 'Holder/properties/either/oneOf/0: Class App\Dto\User is already generated from /project/api/openapi.yaml#/components/schemas/User, so this one becomes App\Dto\User2; set "x-php-class-name" to name it, in the document or "classNames" of the source.'],
            ModelFixture::messages($output),
        );
        $classes = ModelFixture::classes($output);
        self::assertSame(['id: int|null'], $classes['App\Dto\User2']);
        self::assertSame(['m: string|null'], $classes['App\Dto\HolderEitherOption1']);
    }

    public function testGivesAMemberTheClassOfTheSameSchemaHoldingTheNameOfItsPlace(): void
    {
        $member = ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]];
        $output = ModelFixture::build([
            'User' => ['type' => 'object', 'properties' => ['n' => ['type' => 'string']]],
            'HolderEitherOption1' => $member,
            'Holder' => ['type' => 'object', 'properties' => ['either' => ['oneOf' => [['title' => 'User'] + $member, ['type' => 'string']]]]],
        ]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(['either: App\Dto\HolderEitherOption1|string|null'], ModelFixture::classes($output)['App\Dto\Holder']);
        self::assertArrayNotHasKey('App\Dto\User2', ModelFixture::classes($output));
    }

    public function testGivesAnEnumThatAnAllOfMemberRestatesTheEnumOfTheFirst(): void
    {
        $output = ModelFixture::build(['Job' => ['type' => 'object', 'properties' => ['run' => ['allOf' => [
            ['type' => 'object', 'properties' => ['state' => ['type' => ['string', 'null'], 'description' => 'State.', 'enum' => ['open', 'closed', null]]]],
            ['type' => 'object', 'required' => ['state'], 'properties' => ['state' => ['type' => 'string', 'enum' => ['closed', 'open']]]],
        ]]]]]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(['App\Dto\JobRunState'], ModelFixture::enums($output));
        self::assertSame(['state: App\Dto\JobRunState'], ModelFixture::classes($output)['App\Dto\JobRun']);
    }

    public function testGivesAMemberTheEnumWithTheSameCasesHoldingTheNameOfItsPlace(): void
    {
        $output = ModelFixture::build([
            'User' => ['type' => 'object', 'properties' => ['n' => ['type' => 'string']]],
            'HolderEitherOption1' => ['type' => 'string', 'description' => 'A letter.', 'enum' => ['a', 'b']],
            'Holder' => ['type' => 'object', 'properties' => ['either' => ['oneOf' => [['title' => 'User', 'enum' => ['b', 'a']], ['type' => 'integer']]]]],
        ]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(['App\Dto\HolderEitherOption1'], ModelFixture::enums($output));
    }

    public function testGivesTwoEnumsNamedAlikeWithTheSameCasesOneEnum(): void
    {
        $output = ModelFixture::build(['Job' => ['type' => 'object', 'properties' => [
            'from' => ['x-php-class-name' => 'Level', 'enum' => ['low', 'high']],
            'to' => ['x-php-class-name' => 'Level', 'description' => 'Target.', 'enum' => ['high', 'low']],
        ]]]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(['App\Dto\Level'], ModelFixture::enums($output));
    }

    /**
     * @param array<string, mixed> $restated
     *
     * @dataProvider unsharedEnums
     */
    public function testDeclaresAnEnumThatSaysMoreThanItsCases(array $restated, string $warning): void
    {
        $output = ModelFixture::build(['Job' => ['type' => 'object', 'properties' => ['run' => ['allOf' => [
            ['type' => 'object', 'properties' => ['state' => ['enum' => ['open', 'closed']]]],
            ['type' => 'object', 'properties' => ['state' => $restated]],
        ]]]]]);

        self::assertContains(str_replace('error ', 'warning ', self::AT) . 'Job/properties/run/allOf/1/properties/state' . $warning, ModelFixture::messages($output));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function unsharedEnums(): iterable
    {
        yield 'a value listed twice' => [['enum' => ['closed', 'open', 'closed']], '/enum/2: Enum value "closed" is listed twice.'];
        yield 'deprecated' => [['deprecated' => true, 'enum' => ['closed', 'open']], ': Class App\Dto\JobRunState is already generated from /project/api/openapi.yaml#/components/schemas/Job/properties/run/allOf/0/properties/state, so this one becomes App\Dto\JobRunState2; set "x-php-class-name" to name it, in the document or "classNames" of the source.'];
    }

    public function testGivesAnExactCopyOfAnEnumWithAWarningTheEnumItCopies(): void
    {
        $state = ['enum' => ['open', 'closed', 'open']];
        $output = ModelFixture::build(['Job' => ['type' => 'object', 'properties' => ['run' => ['allOf' => [
            ['type' => 'object', 'properties' => ['state' => $state]],
            ['type' => 'object', 'properties' => ['state' => $state]],
        ]]]]]);

        self::assertSame([str_replace('error ', 'warning ', self::AT) . 'Job/properties/run/allOf/0/properties/state/enum/2: Enum value "open" is listed twice.'], ModelFixture::messages($output));
        self::assertSame(['App\\Dto\\JobRunState'], ModelFixture::enums($output));
    }

    public function testNumbersAnEnumThatAnAllOfMemberNarrows(): void
    {
        $output = ModelFixture::build(['Job' => ['type' => 'object', 'properties' => ['run' => ['allOf' => [
            ['type' => 'object', 'properties' => ['state' => ['enum' => ['open', 'closed']]]],
            ['type' => 'object', 'properties' => ['state' => ['enum' => ['closed']]]],
        ]]]]]);

        self::assertSame(
            [str_replace('error ', 'warning ', self::AT) . 'Job/properties/run/allOf/1/properties/state: Class App\Dto\JobRunState is already generated from /project/api/openapi.yaml#/components/schemas/Job/properties/run/allOf/0/properties/state, so this one becomes App\Dto\JobRunState2; set "x-php-class-name" to name it, in the document or "classNames" of the source.'],
            ModelFixture::messages($output),
        );
    }

    public function testMakesAClassOfAnInlineDiscriminatedUnionWithInlineVariants(): void
    {
        $output = ModelFixture::build([
            'Holder' => ['type' => 'object', 'properties' => ['pet' => [
                'oneOf' => [['type' => 'object', 'properties' => ['kind' => ['type' => 'string', 'const' => 'cat']]], ['$ref' => '#/components/schemas/Dog']],
                'discriminator' => ['propertyName' => 'kind'],
            ]]],
            'Dog' => ['type' => 'object', 'properties' => ['kind' => ['type' => 'string', 'const' => 'Dog']]],
        ]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(['pet: App\\Dto\\HolderPet|null'], ModelFixture::classes($output)['App\\Dto\\Holder']);
        $hierarchy = ModelFixture::hierarchy($output);
        self::assertSame('abstract by kind {cat: App\\Dto\\HolderPetOption1, Dog: App\\Dto\\Dog}', $hierarchy['App\\Dto\\HolderPet']);
        self::assertSame('final extends App\\Dto\\HolderPet', $hierarchy['App\\Dto\\HolderPetOption1']);
        self::assertSame('final extends App\\Dto\\HolderPet', $hierarchy['App\\Dto\\Dog']);
    }

    public function testMakesAClassOfAnInlineDiscriminatedUnionWithInlineAnyOfVariants(): void
    {
        $output = ModelFixture::build([
            'Holder' => ['type' => 'object', 'properties' => ['pet' => [
                'anyOf' => [['type' => 'object', 'properties' => ['kind' => ['type' => 'string', 'const' => 'cat']]], ['$ref' => '#/components/schemas/Dog']],
                'discriminator' => ['propertyName' => 'kind'],
            ]]],
            'Dog' => ['type' => 'object', 'properties' => ['kind' => ['type' => 'string', 'const' => 'Dog']]],
        ]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame('abstract by kind {cat: App\\Dto\\HolderPetOption1, Dog: App\\Dto\\Dog}', ModelFixture::hierarchy($output)['App\\Dto\\HolderPet']);
    }

    public function testKeepsAnInlineDiscriminatedUnionWithoutInlineObjectsAType(): void
    {
        $output = ModelFixture::build([
            'Holder' => ['type' => 'object', 'properties' => ['pet' => [
                'oneOf' => [['$ref' => '#/components/schemas/Dog'], ['type' => 'string']],
                'discriminator' => ['propertyName' => 'kind'],
            ]]],
            'Dog' => ['type' => 'object', 'properties' => ['kind' => ['type' => 'string', 'const' => 'Dog']]],
        ]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(['pet: App\\Dto\\Dog|string|null'], ModelFixture::classes($output)['App\\Dto\\Holder']);
        self::assertSame(['App\\Dto\\Holder', 'App\\Dto\\Dog'], array_keys(ModelFixture::classes($output)));
    }

    public function testFindsTheValueOfAnInlineVariantBehindARefAndInItsAllOf(): void
    {
        $output = ModelFixture::build([
            'Event' => [
                'oneOf' => [
                    ['type' => 'object', 'properties' => ['type' => ['$ref' => '#/components/schemas/CreatedType']]],
                    ['type' => 'object', 'properties' => ['type' => ['type' => 'string']], 'allOf' => [['properties' => ['type' => ['const' => 'deleted']]]]],
                    ['type' => 'object', 'properties' => ['type' => ['type' => 'integer', 'const' => 7]]],
                    ['type' => 'object', 'properties' => ['type' => ['type' => ['string', 'null'], 'enum' => [null, 'moved']]]],
                ],
                'discriminator' => ['propertyName' => 'type'],
            ],
            'CreatedType' => ['type' => 'string', 'enum' => ['created']],
        ]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame('abstract by type {created: App\\Dto\\EventOption1, deleted: App\\Dto\\EventOption2, 7: App\\Dto\\EventOption3, moved: App\\Dto\\EventOption4}', ModelFixture::hierarchy($output)['App\\Dto\\Event']);
    }

    public function testRefusesTwoInlineVariantsWithOneValue(): void
    {
        $variant = ['type' => 'object', 'properties' => ['type' => ['type' => 'string', 'const' => 'x']]];
        $other = ['type' => 'object', 'properties' => ['type' => ['type' => 'string', 'const' => 'y']]];
        $output = ModelFixture::build(['Event' => ['oneOf' => [$variant, $variant, $other], 'discriminator' => ['propertyName' => 'type']]]);

        self::assertSame(
            ['error /project/api/openapi.yaml#/components/schemas/Event/oneOf/1: Another variant already has discriminator value "x", so App\\Dto\\EventOption2 can never be selected.'],
            ModelFixture::messages($output),
        );
        self::assertSame('abstract by type {x: App\\Dto\\EventOption1, y: App\\Dto\\EventOption3}', ModelFixture::hierarchy($output)['App\\Dto\\Event']);
    }

    public function testRefusesAnInlineVariantWhoseValueIsNoStringOrIntegerOrLoops(): void
    {
        $output = ModelFixture::build([
            'Event' => [
                'oneOf' => [
                    ['type' => 'object', 'properties' => ['type' => ['type' => 'boolean', 'const' => true]]],
                    ['type' => 'object', 'properties' => ['type' => ['$ref' => '#/components/schemas/A']]],
                ],
                'discriminator' => ['propertyName' => 'type'],
            ],
            'A' => ['$ref' => '#/components/schemas/B'],
            'B' => ['$ref' => '#/components/schemas/A'],
        ], [], ['*'], AllOfStrategy::EXTENDS, false);

        $message = ': An inline variant of a discriminated union needs one value of "type", in "const" or a one-value "enum", as the mapping cannot name it.';
        self::assertContains(self::AT . 'Event/oneOf/0' . $message, ModelFixture::messages($output));
        self::assertContains(self::AT . 'Event/oneOf/1' . $message, ModelFixture::messages($output));
    }

    public function testRefusesAnInlineVariantWithoutAValue(): void
    {
        $output = ModelFixture::build(['Holder' => ['type' => 'object', 'properties' => ['pet' => [
            'oneOf' => [['type' => 'object', 'properties' => ['kind' => ['type' => 'string']]]],
            'discriminator' => ['propertyName' => 'kind'],
        ]]]]);

        self::assertSame(
            [self::AT . 'Holder/properties/pet/oneOf/0: An inline variant of a discriminated union needs one value of "kind", in "const" or a one-value "enum", as the mapping cannot name it.'],
            ModelFixture::messages($output),
        );
    }

    public function testChecksTheExtensionKeysOfInlineMembers(): void
    {
        $output = ModelFixture::build(['Holder' => ['type' => 'object', 'properties' => ['either' => ['oneOf' => [
            ['type' => 'object', 'x-php-clas-name' => 'Typo', 'properties' => ['a' => ['type' => 'string']]],
            ['type' => 'string', 'x-dto-mutable' => true],
        ]]]]]);

        self::assertSame(
            [
                self::AT . 'Holder/properties/either/oneOf/0/x-php-clas-name: Unknown extension "x-php-clas-name"; known: x-php-class-name, x-php-name, x-php-type, x-dto-mutable, x-php-all-of, x-php-skip, x-php-attributes, x-php-read-write, x-enum-descriptions, x-enum-varnames.',
                'warning /project/api/openapi.yaml#/components/schemas/Holder/properties/either/oneOf/1/x-dto-mutable: "x-dto-mutable" has no effect here.',
            ],
            ModelFixture::messages($output),
        );
    }

    public function testHoistsInlineSchemasOfANamedAlias(): void
    {
        $output = ModelFixture::build([
            'Pets' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]]],
            'Tags' => ['type' => 'object', 'additionalProperties' => ['type' => 'string', 'enum' => ['a', 'b']]],
            'Shape' => ['oneOf' => [['type' => 'object', 'properties' => ['r' => ['type' => 'number']]], ['type' => 'object', 'properties' => ['w' => ['type' => 'number']]]]],
            'Holder' => ['type' => 'object', 'properties' => [
                'pets' => ['$ref' => '#/components/schemas/Pets'],
                'tags' => ['$ref' => '#/components/schemas/Tags'],
                'shape' => ['$ref' => '#/components/schemas/Shape'],
            ]],
        ]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(
            ['pets: list<App\Dto\PetsItem>|null', 'tags: array<array-key, App\Dto\TagsValue>|null', 'shape: App\Dto\ShapeOption1|App\Dto\ShapeOption2|null'],
            ModelFixture::classes($output)['App\Dto\Holder'],
        );
        self::assertSame(['App\Dto\TagsValue'], ModelFixture::enums($output));
    }

    public function testLeavesASkippedAliasAlone(): void
    {
        $output = ModelFixture::build(['Pets' => ['type' => 'array', 'x-php-skip' => true, 'items' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]]]]);

        self::assertSame([], ModelFixture::classes($output));
    }

    public function testNamesAnArrayMemberAfterItsPosition(): void
    {
        $output = ModelFixture::build(['Holder' => ['type' => 'object', 'properties' => ['either' => ['oneOf' => [
            ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]]],
            ['type' => 'string'],
        ]]]]]);

        self::assertArrayHasKey('App\Dto\HolderEitherOption1Item', ModelFixture::classes($output));
    }

    public function testNamesAliasMembersAfterTheirTitle(): void
    {
        $output = ModelFixture::build(['Shape' => ['oneOf' => [
            ['type' => 'object', 'title' => 'circle', 'properties' => ['r' => ['type' => 'number']]],
            ['type' => 'string'],
        ]]]);

        self::assertSame(['App\Dto\Circle'], array_keys(ModelFixture::classes($output)));
    }

    public function testReportsEveryInlineMemberOfAnInlineDiscriminatedUnion(): void
    {
        $output = ModelFixture::build(['Holder' => ['type' => 'object', 'properties' => ['pet' => [
            'oneOf' => [
                ['type' => 'object', 'properties' => ['kind' => ['type' => 'string']]],
                ['type' => 'object', 'properties' => ['kind' => ['type' => 'string']]],
            ],
            'discriminator' => ['propertyName' => 'kind'],
        ]]]]);

        $message = ': An inline variant of a discriminated union needs one value of "kind", in "const" or a one-value "enum", as the mapping cannot name it.';
        self::assertSame([self::AT . 'Holder/properties/pet/oneOf/0' . $message, self::AT . 'Holder/properties/pet/oneOf/1' . $message], ModelFixture::messages($output));
    }

    public function testChecksAnyOfMembersButNotReferencedOnes(): void
    {
        $output = ModelFixture::build([
            'User' => ['type' => 'object', 'properties' => ['n' => ['type' => 'string']]],
            'Holder' => ['type' => 'object', 'properties' => ['either' => [
                'oneOf' => [['$ref' => '#/components/schemas/User', 'x-dto-mutable' => true]],
                'anyOf' => [['type' => 'string', 'x-dto-mutable' => true]],
            ]]],
        ]);

        self::assertSame(
            [
                'warning /project/api/openapi.yaml#/components/schemas/Holder/properties/either/anyOf/0/x-dto-mutable: "x-dto-mutable" has no effect here.',
                'warning /project/api/openapi.yaml#/components/schemas/Holder/properties/either: "oneOf" and "anyOf" together become one union, which admits more than the schema does.',
            ],
            ModelFixture::messages($output),
        );
    }

    public function testListsEachLiteralOfAMixedEnumOnce(): void
    {
        $output = ModelFixture::build(['C' => ['type' => 'object', 'required' => ['m'], 'properties' => ['m' => ['enum' => ['a', 'a', 1]]]]]);

        self::assertSame(["m: 'a'|1"], ModelFixture::classes($output)['App\Dto\C']);
    }

    public function testLetsNamedComponentsKeepTheirNamesOverAliasMembers(): void
    {
        $output = ModelFixture::build([
            'Shape' => ['oneOf' => [['type' => 'object', 'title' => 'User', 'properties' => ['r' => ['type' => 'number']]], ['type' => 'string']]],
            'Pets' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]]],
            'User' => ['type' => 'object', 'properties' => ['n' => ['type' => 'string']]],
            'PetsItem' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]],
        ]);

        $classes = ModelFixture::classes($output);
        self::assertSame(['n: string|null'], $classes['App\Dto\User']);
        self::assertSame(['id: int|null'], $classes['App\Dto\PetsItem']);
        $messages = ModelFixture::messages($output);
        self::assertSame([str_replace('error ', 'warning ', self::AT) . 'Pets/items: Class App\Dto\PetsItem is already generated from /project/api/openapi.yaml#/components/schemas/PetsItem, so this one becomes App\Dto\PetsItem2; set "x-php-class-name" to name it, in the document or "classNames" of the source.'], $messages);
        self::assertArrayHasKey('App\Dto\ShapeOption1', $classes);
    }

    public function testTypesAUnionOfConstMembers(): void
    {
        $output = ModelFixture::build(['C' => ['type' => 'object', 'properties' => [
            'e' => ['oneOf' => [['const' => 'a', 'title' => 'A'], ['const' => 'b']]],
            't' => ['type' => 'string', 'oneOf' => [['const' => 'a'], ['const' => 'b']]],
        ]]]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(["e: 'a'|'b'|null", "t: 'a'|'b'|null"], ModelFixture::classes($output)['App\Dto\C']);
    }

    public function testDropsALiteralAPlainMemberOfItsKindSubsumes(): void
    {
        $output = ModelFixture::build(['C' => ['type' => 'object', 'properties' => [
            'e' => ['anyOf' => [['enum' => ['a', 1]], ['type' => 'string']]],
            'n' => ['oneOf' => [['type' => 'string', 'minLength' => 1], ['type' => 'string']]],
        ]]]);

        self::assertSame(['e: 1|string|null', 'n: string|null'], ModelFixture::classes($output)['App\Dto\C']);
    }

    public function testReportsInlineMembersOfADiscriminatedInlineClass(): void
    {
        $output = ModelFixture::build(['Holder' => ['type' => 'object', 'properties' => ['pet' => [
            'properties' => ['kind' => ['type' => 'string']],
            'oneOf' => [['type' => 'object', 'properties' => ['kind' => ['type' => 'string']]], ['type' => 'object', 'properties' => ['kind' => ['type' => 'string']]]],
            'discriminator' => ['propertyName' => 'kind'],
        ]]]]);

        $message = ': An inline object in a oneOf or anyOf with a discriminator is not generated: the discriminator mapping needs a $ref. Move it to components/schemas.';
        self::assertSame([self::AT . 'Holder/properties/pet/oneOf/0' . $message, self::AT . 'Holder/properties/pet/oneOf/1' . $message], ModelFixture::messages($output));
    }

    public function testHoistsNothingBehindXPhpType(): void
    {
        $output = ModelFixture::build(['Holder' => ['type' => 'object', 'properties' => ['e' => [
            'x-php-type' => 'Foo\Bar',
            'oneOf' => [['type' => 'object', 'properties' => ['a' => ['type' => 'string']]], ['type' => 'string']],
        ]]]]);

        self::assertSame(['App\Dto\Holder'], array_keys(ModelFixture::classes($output)));
    }

    public function testComparesDefaultsWithConstsAsJsonValues(): void
    {
        $output = ModelFixture::build(['C' => ['type' => 'object', 'properties' => [
            'a' => ['type' => 'number', 'const' => 2.0, 'default' => 2],
            'b' => ['type' => 'number', 'const' => 2, 'default' => 2.0],
            'c' => ['const' => 'x', 'default' => 'x'],
            'd' => ['const' => 1, 'default' => '1'],
            'e' => ['type' => 'string', 'const' => '1', 'default' => 1],
            'f' => ['const' => 'a/é', 'default' => 'b'],
            'g' => ['enum' => ['a', 1], 'default' => 1],
            'h' => ['type' => 'number', 'const' => 2.0, 'default' => 3],
        ]]]);

        $mixed = 'warning /project/api/openapi.yaml#/components/schemas/C/properties/g/enum: The enum mixes strings and integers, which no PHP enum can back; the property takes either.';
        self::assertSame(
            [
                self::AT . 'C/properties/d/default: Default "1" is not the "const" value 1; null is used instead.',
                self::AT . 'C/properties/e/default: Default 1 is not the "const" value "1"; null is used instead.',
                self::AT . 'C/properties/f/default: Default "b" is not the "const" value "a/é"; null is used instead.',
                $mixed,
                self::AT . 'C/properties/h/default: Default 3 is not the "const" value 2.0; null is used instead.',
            ],
            ModelFixture::messages($output),
        );
    }

    public function testKeepsTheDeclaredTypeAgainstAConstOfAnotherKind(): void
    {
        $output = ModelFixture::build(['C' => ['type' => 'object', 'properties' => [
            'i' => ['type' => 'integer', 'const' => 'x'],
            'b' => ['type' => 'string', 'const' => true],
        ]]]);

        self::assertCount(2, ModelFixture::messages($output));
        self::assertSame(['i: int|null', 'b: string|null'], ModelFixture::classes($output)['App\\Dto\\C']);
    }

    public function testNarrowsATypedMemberByAConstInAllOf(): void
    {
        $output = ModelFixture::build(['C' => ['type' => 'object', 'properties' => ['a' => ['allOf' => [['type' => 'string'], ['const' => 'x']]]]]]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(["a: 'x'|null"], ModelFixture::classes($output)['App\\Dto\\C']);
    }

    public function testIgnoresANamedEnumItsTypeAdmitsNoneOf(): void
    {
        $output = ModelFixture::build([
            'Regions' => ['type' => 'array', 'items' => ['type' => 'integer'], 'enum' => ['EU', 'HK']],
            'Level' => ['type' => ['integer', 'string'], 'enum' => [1, 'a']],
            'C' => ['type' => 'object', 'properties' => ['r' => ['$ref' => '#/components/schemas/Regions'], 'l' => ['$ref' => '#/components/schemas/Level']]],
        ]);

        self::assertSame(['r: list<int>|null', "l: 'a'|1|null"], ModelFixture::classes($output)['App\\Dto\\C']);
        self::assertSame(
            [
                'warning /project/api/openapi.yaml#/components/schemas/Regions/type: "type" admits none of the enum values, so the enum is ignored and the property keeps its type.',
                'warning /project/api/openapi.yaml#/components/schemas/Level/enum: The enum mixes strings and integers, which no PHP enum can back; the property takes either.',
            ],
            ModelFixture::messages($output),
        );
    }

    public function testTypesAValueThatHoldsItselfInAListOrAMap(): void
    {
        $output = ModelFixture::build([
            'Value' => ['type' => 'null', 'oneOf' => [
                ['type' => 'string'],
                ['type' => 'number'],
                ['type' => 'boolean'],
                ['type' => 'object'],
                ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Value']],
            ]],
            'Tree' => ['type' => 'object', 'additionalProperties' => ['$ref' => '#/components/schemas/Tree']],
            'Loop' => ['$ref' => '#/components/schemas/Loop2'],
            'Loop2' => ['allOf' => [['$ref' => '#/components/schemas/Loop']]],
            'C' => ['type' => 'object', 'required' => ['v', 't'], 'properties' => [
                'v' => ['$ref' => '#/components/schemas/Value'],
                't' => ['$ref' => '#/components/schemas/Tree'],
                'l' => ['$ref' => '#/components/schemas/Loop'],
                'll' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Loop']],
            ]],
        ]);

        self::assertSame(['v: string|float|bool|array<array-key, mixed>|list<mixed>|null', 't: array<array-key, mixed>', 'l: mixed', 'll: list<mixed>|null'], ModelFixture::classes($output)['App\\Dto\\C']);
        self::assertSame(['error /project/api/openapi.yaml#/components/schemas/Loop: The $ref chain loops back to itself without reaching an object schema.'], ModelFixture::messages($output));
    }

    public function testDropsAFloatDefaultOfAnIntegerConst(): void
    {
        $output = ModelFixture::build(['C' => ['type' => 'object', 'properties' => ['i' => ['type' => 'integer', 'const' => 2, 'default' => 2.0]]]]);

        // JSON-equal, but `int $i = 2.0` does not compile.
        self::assertSame(['warning /project/api/openapi.yaml#/components/schemas/C/properties/i/default: Default 2.0 does not match 2; null is used instead.'], ModelFixture::messages($output));
    }

    public function testComparesNestedDefaultsAsJsonValues(): void
    {
        $output = ModelFixture::build(['C' => ['type' => 'object', 'properties' => [
            'l' => ['const' => [1, 2.0], 'default' => [1, 2]],
            'm' => ['const' => ['a' => 1, 'b' => 2], 'default' => ['b' => 2, 'a' => 1]],
            'n' => ['const' => [1, 2], 'default' => [1, 3]],
            'o' => ['const' => ['a' => 1], 'default' => ['b' => 1]],
            'p' => ['const' => [1], 'default' => ['a' => 1]],
        ]]]);

        self::assertSame(
            [
                self::AT . 'C/properties/n/default: Default [1,3] is not the "const" value [1,2]; null is used instead.',
                self::AT . 'C/properties/o/default: Default {"b":1} is not the "const" value {"a":1}; null is used instead.',
                self::AT . 'C/properties/p/default: Default {"a":1} is not the "const" value [1]; null is used instead.',
            ],
            array_values(array_filter(ModelFixture::messages($output), static fn (string $m): bool => strpos($m, '"const" value') !== false)),
        );
    }

    public function testFindsTheConstBehindAReference(): void
    {
        $output = ModelFixture::build([
            'Two' => ['type' => 'number', 'const' => 2.5],
            'Txt' => ['type' => 'string', 'const' => 'a|b'],
            'Mix' => ['enum' => ["it's", 1]],
            'C' => ['type' => 'object', 'properties' => [
                'r' => ['$ref' => '#/components/schemas/Two', 'default' => 3.5],
                'a' => ['allOf' => [['$ref' => '#/components/schemas/Txt']], 'default' => 'zz'],
                'm' => ['$ref' => '#/components/schemas/Mix', 'default' => 'zz'],
                'ok' => ['$ref' => '#/components/schemas/Two', 'default' => 2.5],
            ]],
        ]);

        self::assertSame(
            [
                self::AT . 'C/properties/r/default: Default 3.5 is not the "const" value 2.5; null is used instead.',
                self::AT . 'C/properties/a/default: Default "zz" is not the "const" value "a|b"; null is used instead.',
                self::AT . 'C/properties/m/default: Default "zz" is not one of the "enum" values; null is used instead.',
            ],
            array_values(array_filter(ModelFixture::messages($output), static fn (string $m): bool => strpos($m, 'error') === 0)),
        );
    }

    public function testKeepsTheLiteralOfAnUntypedDateConst(): void
    {
        $output = ModelFixture::build(['C' => ['type' => 'object', 'properties' => [
            'e' => ['const' => '2020-01-01', 'format' => 'date'],
            't' => ['type' => 'string', 'const' => '2020-01-01', 'format' => 'date'],
        ]]]);

        self::assertSame(["e: '2020-01-01'|null", 't: DateTimeImmutable|null'], ModelFixture::classes($output)['App\\Dto\\C']);
    }

    public function testExplainsAnIntegerConstBeyondTheRangeOfInt(): void
    {
        $output = ModelFixture::build(['C' => ['type' => 'object', 'properties' => ['big' => ['type' => 'integer', 'const' => 1.0e20]]]]);

        self::assertSame(
            ['warning /project/api/openapi.yaml#/components/schemas/C/properties/big/const: "const" is outside the range of PHP int; the property keeps int.'],
            ModelFixture::messages($output),
        );
    }

    public function testGivesBothBooleanConstsTheirType(): void
    {
        $output = ModelFixture::build(['C' => ['type' => 'object', 'properties' => ['f' => ['oneOf' => [['const' => true], ['const' => false]]]]]]);

        self::assertSame(['f: bool|null'], ModelFixture::classes($output)['App\\Dto\\C']);
    }

    public function testFindsTheConstOfAnyCompositionMember(): void
    {
        $output = ModelFixture::build([
            'S' => ['type' => 'string'],
            'Color' => ['type' => 'string', 'enum' => ['a', 'b'], 'const' => 'a'],
            'LoopA' => ['$ref' => '#/components/schemas/LoopB'],
            'LoopB' => ['$ref' => '#/components/schemas/LoopA'],
            'C' => ['type' => 'object', 'properties' => [
                'a' => ['allOf' => [['type' => 'string'], ['const' => 'x']], 'default' => 'y'],
                'b' => ['allOf' => [['$ref' => '#/components/schemas/S', 'const' => 'x']], 'default' => 'y'],
                'c' => ['allOf' => [['enum' => ['a', 1]]], 'default' => 'zz'],
                'd' => ['$ref' => '#/components/schemas/Color', 'default' => 'b'],
                'e' => ['$ref' => '#/components/schemas/LoopA', 'default' => 'x'],
            ]],
        ]);

        self::assertSame(
            [
                self::AT . 'C/properties/a/default: Default "y" is not the "const" value "x"; null is used instead.',
                self::AT . 'C/properties/b/default: Default "y" is not the "const" value "x"; null is used instead.',
                self::AT . 'C/properties/c/default: Default "zz" is not one of the "enum" values; null is used instead.',
                self::AT . 'C/properties/d/default: Default "b" is not the "const" value "a"; null is used instead.',
            ],
            array_values(array_filter(ModelFixture::messages($output), static fn (string $m): bool => strpos($m, '/default:') !== false)),
        );
    }

    public function testTellsArraysOfAnotherShapeApart(): void
    {
        $output = ModelFixture::build(['C' => ['type' => 'object', 'properties' => [
            'a' => ['const' => [1], 'default' => 1],
            'b' => ['const' => [1, 2], 'default' => [1]],
            'c' => ['const' => ['a', 'b'], 'default' => [1 => 'b', 0 => 'a']],
        ]]]);

        self::assertSame(
            [
                self::AT . 'C/properties/a/default: Default 1 is not the "const" value [1]; null is used instead.',
                self::AT . 'C/properties/b/default: Default [1] is not the "const" value [1,2]; null is used instead.',
                self::AT . 'C/properties/c/default: Default {"1":"b","0":"a"} is not the "const" value ["a","b"]; null is used instead.',
            ],
            array_values(array_filter(ModelFixture::messages($output), static fn (string $m): bool => strpos($m, '"const" value') !== false)),
        );
    }

    public function testKeepsALoneBooleanLiteralBesideAnotherType(): void
    {
        $output = ModelFixture::build(['C' => ['type' => 'object', 'properties' => ['e' => ['oneOf' => [['const' => true], ['type' => 'string']]]]]]);

        self::assertSame(['e: true|string|null'], ModelFixture::classes($output)['App\\Dto\\C']);
    }

    public function testNarrowsATypeByAConstInAllOf(): void
    {
        $output = ModelFixture::build([
            'S' => ['type' => 'string'],
            'C' => ['type' => 'object', 'properties' => [
                'a' => ['allOf' => [['const' => 'x']]],
                'b' => ['allOf' => [['const' => 5]], 'default' => 5],
                'c' => ['type' => 'string', 'allOf' => [['const' => 'x']]],
                'd' => ['allOf' => [['$ref' => '#/components/schemas/S'], ['const' => 'x']]],
                'e' => ['type' => 'number', 'allOf' => [['const' => 5]]],
                'f' => ['type' => 'string', 'allOf' => [['const' => 5]]],
            ]],
        ]);

        self::assertSame(
            ['warning /project/api/openapi.yaml#/components/schemas/C/properties/f/allOf/0/const: "const" is outside the type the rest of the schema gives, so no value is valid; the type is kept.'],
            ModelFixture::messages($output),
        );
        self::assertSame(["a: 'x'|null", 'b: 5|null', "c: 'x'|null", "d: 'x'|null", 'e: float|null', 'f: string|null'], ModelFixture::classes($output)['App\\Dto\\C']);
    }

    public function testTakesAHugeIntegralConstAsANumberWhenItMayBeOne(): void
    {
        $output = ModelFixture::build(['C' => ['type' => 'object', 'properties' => ['big' => ['type' => ['integer', 'number'], 'const' => 1.0e20]]]]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(['big: float|null'], ModelFixture::classes($output)['App\\Dto\\C']);
    }

    public function testChecksADefaultAgainstEveryConstAndEnum(): void
    {
        $output = ModelFixture::build(['C' => ['type' => 'object', 'properties' => [
            'a' => ['allOf' => [['enum' => [1, 'a', 2]], ['const' => 2]], 'default' => 1],
        ]]]);

        self::assertContains(self::AT . 'C/properties/a/default: Default 1 is not the "const" value 2; null is used instead.', ModelFixture::messages($output));
    }

    public function testLooksNoFurtherThanAGeneratedClass(): void
    {
        $output = ModelFixture::build([
            'Point' => ['type' => 'object', 'const' => ['x' => 1], 'properties' => ['x' => ['type' => 'integer']]],
            'C' => ['type' => 'object', 'properties' => ['p' => ['$ref' => '#/components/schemas/Point', 'default' => ['x' => 2]]]],
        ]);

        self::assertSame(
            ['warning /project/api/openapi.yaml#/components/schemas/C/properties/p/default: A default for App\\Dto\\Point cannot be a PHP constant expression; null is used instead.'],
            ModelFixture::messages($output),
        );
    }

    public function testChecksTheFormatOfAConst(): void
    {
        $output = ModelFixture::build(['C' => ['type' => 'object', 'properties' => ['n' => ['type' => 'integer', 'const' => 5, 'format' => 'date']]]]);

        self::assertSame(
            ['warning /project/api/openapi.yaml#/components/schemas/C/properties/n/format: Unknown integer format "date"; the property stays an int. "formats" in the config can map it to a type.'],
            ModelFixture::messages($output),
        );
        self::assertSame(['n: 5|null'], ModelFixture::classes($output)['App\\Dto\\C']);
    }

    public function testChecksAnOwnConstBesideAllOfMembers(): void
    {
        $output = ModelFixture::build(['C' => ['type' => 'object', 'properties' => [
            'a' => ['const' => 2, 'allOf' => [['enum' => [1, 'a', 2]]], 'default' => 1],
        ]]]);

        self::assertContains(self::AT . 'C/properties/a/default: Default 1 is not the "const" value 2; null is used instead.', ModelFixture::messages($output));
    }

    public function testChecksTheFormatOfEveryKindOfConst(): void
    {
        $output = ModelFixture::build(['C' => ['type' => 'object', 'properties' => [
            's' => ['const' => 'x', 'format' => 'odd'],
            'f' => ['const' => 1.5, 'format' => 'odd'],
            'b' => ['const' => true, 'format' => 'odd'],
        ]]]);

        self::assertSame(
            [
                'warning /project/api/openapi.yaml#/components/schemas/C/properties/s/format: Unknown string format "odd"; the property stays a string. "formats" in the config can map it to a type.',
                'warning /project/api/openapi.yaml#/components/schemas/C/properties/f/format: Unknown number format "odd"; the property stays a float. "formats" in the config can map it to a type.',
            ],
            ModelFixture::messages($output),
        );
    }

    public function testKeepsTheNullabilityOfANarrowedType(): void
    {
        $output = ModelFixture::build(['C' => ['type' => 'object', 'required' => ['n', 's'], 'properties' => [
            'n' => ['type' => ['string', 'null'], 'allOf' => [['const' => 'x']]],
            's' => ['type' => 'string', 'allOf' => [['const' => 'x']]],
        ]]]);

        self::assertSame(["n: 'x'|null", "s: 'x'"], ModelFixture::classes($output)['App\\Dto\\C']);
    }

    public function testKnowsDateFormatsOfAnUntypedConst(): void
    {
        $output = ModelFixture::build(['C' => ['type' => 'object', 'properties' => [
            'a' => ['const' => '2020-01-01', 'format' => 'date'],
            'b' => ['type' => 'string', 'allOf' => [['const' => '2020-01-01', 'format' => 'date']]],
        ]]]);

        self::assertSame([], ModelFixture::messages($output));
    }

    public function testLeavesAConstOfAMappedMemberToTheMapping(): void
    {
        $output = ModelFixture::build(['C' => ['type' => 'object', 'properties' => [
            'a' => ['allOf' => [['x-php-type' => 'App\\Foo', 'type' => 'integer', 'const' => 'x']]],
        ]]]);

        // Only the vocabulary check's note, which predates allOf members with x-php-type.
        self::assertSame(
            ['warning /project/api/openapi.yaml#/components/schemas/C/properties/a/allOf/0/x-php-type: "x-php-type" has no effect here.'],
            ModelFixture::messages($output),
        );
        self::assertSame(['a: App\\Foo|null'], ModelFixture::classes($output)['App\\Dto\\C']);
    }

    public function testWarnsAboutAConstNoValueOfTheTypeMatches(): void
    {
        $output = ModelFixture::build(['C' => ['type' => 'object', 'properties' => [
            'r' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10, 'allOf' => [['const' => 50]]],
            't' => ['allOf' => [['const' => 'x'], ['const' => 'y']]],
            'ok' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10, 'allOf' => [['const' => 5]]],
            'next' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10, 'allOf' => [['const' => 50], ['const' => 5]]],
        ]]]);

        $warning = 'warning /project/api/openapi.yaml#/components/schemas/C/properties/%s/const: "const" is outside the type the rest of the schema gives, so no value is valid; the type is kept.';
        self::assertSame([sprintf($warning, 'r/allOf/0'), sprintf($warning, 't/allOf/1'), sprintf($warning, 'next/allOf/0')], ModelFixture::messages($output));
        self::assertSame(['r: int<1, 10>|null', "t: 'x'|null", 'ok: 5|null', 'next: 5|null'], ModelFixture::classes($output)['App\\Dto\\C']);
    }

    public function testTakesAWholeFloatConstAsTheInteger(): void
    {
        $output = ModelFixture::build(['C' => ['type' => 'object', 'properties' => [
            'a' => ['type' => 'integer', 'allOf' => [['const' => 5.0]]],
            'b' => ['allOf' => [['const' => 5], ['const' => 5.0]]],
            'c' => ['allOf' => [['const' => 5.0], ['const' => 5]]],
            'd' => ['allOf' => [['const' => 5.0]]],
        ]]]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(['a: 5|null', 'b: 5|null', 'c: 5|null', 'd: 5|null'], ModelFixture::classes($output)['App\\Dto\\C']);
    }

    public function testWarnsAboutTwoConstsOfANumberThatDiffer(): void
    {
        $output = ModelFixture::build(['C' => ['type' => 'object', 'properties' => [
            'n' => ['type' => 'number', 'allOf' => [['const' => 5], ['const' => 6]]],
            'f' => ['type' => 'number', 'allOf' => [['const' => 2.5], ['const' => 2]]],
            's' => ['allOf' => [['const' => '5'], ['const' => 5]]],
            'same' => ['type' => 'number', 'allOf' => [['const' => 2.5], ['const' => 2.5]]],
        ]]]);

        $warning = 'warning /project/api/openapi.yaml#/components/schemas/C/properties/%s/const: "const" is outside the type the rest of the schema gives, so no value is valid; the type is kept.';
        self::assertSame([sprintf($warning, 'n/allOf/1'), sprintf($warning, 'f/allOf/1'), sprintf($warning, 's/allOf/1')], ModelFixture::messages($output));
    }
}
