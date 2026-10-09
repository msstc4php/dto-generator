<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Application\Service\Model;

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
        self::assertSame(['whole: 2|null', 'real: float|null', 'ratio: float|null', 'bare: float|null'], ModelFixture::classes($output)['App\Dto\C']);
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

    public function testReportsATitleThatCollides(): void
    {
        $output = ModelFixture::build([
            'User' => ['type' => 'object', 'properties' => ['n' => ['type' => 'string']]],
            'Holder' => ['type' => 'object', 'properties' => ['either' => ['oneOf' => [
                ['type' => 'object', 'title' => 'User', 'properties' => ['id' => ['type' => 'integer']]],
                ['type' => 'string'],
            ]]]],
        ]);

        $collisions = array_filter(ModelFixture::messages($output), static fn (string $m): bool => strpos($m, 'x-php-class-name') !== false);
        self::assertCount(1, $collisions);
    }

    public function testRefusesInlineMembersOfAnInlineDiscriminatedUnion(): void
    {
        $output = ModelFixture::build(['Holder' => ['type' => 'object', 'properties' => ['pet' => [
            'oneOf' => [['type' => 'object', 'properties' => ['kind' => ['type' => 'string']]]],
            'discriminator' => ['propertyName' => 'kind'],
        ]]]]);

        self::assertSame(
            [self::AT . 'Holder/properties/pet/oneOf/0: An inline object in a oneOf or anyOf with a discriminator is not generated: the discriminator mapping needs a $ref. Move it to components/schemas.'],
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
                self::AT . 'Holder/properties/either/oneOf/0/x-php-clas-name: Unknown extension "x-php-clas-name"; known: x-php-class-name, x-php-name, x-php-type, x-dto-mutable, x-php-all-of, x-php-skip, x-php-attributes, x-enum-descriptions, x-enum-varnames.',
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

        $message = ': An inline object in a oneOf or anyOf with a discriminator is not generated: the discriminator mapping needs a $ref. Move it to components/schemas.';
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
        self::assertCount(2, $messages);
        self::assertStringStartsWith(self::AT . 'Shape/oneOf/0:', $messages[0]);
        self::assertStringStartsWith(self::AT . 'Pets/items:', $messages[1]);
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
            'oneOf' => [['type' => 'object', 'properties' => ['kind' => ['type' => 'string']]]],
            'discriminator' => ['propertyName' => 'kind'],
        ]]]]);

        self::assertSame(
            [self::AT . 'Holder/properties/pet/oneOf/0: An inline object in a oneOf or anyOf with a discriminator is not generated: the discriminator mapping needs a $ref. Move it to components/schemas.'],
            ModelFixture::messages($output),
        );
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
            'big' => ['type' => 'integer', 'const' => 1.0e20],
        ]]]);

        self::assertCount(3, ModelFixture::messages($output));
        self::assertSame(['i: int|null', 'b: string|null', 'big: int|null'], ModelFixture::classes($output)['App\\Dto\\C']);
    }
}
