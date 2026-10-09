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
        self::assertSame(["s: 'card'", 'i: 5', 'b: true', 'f: float|null', 'u: string|null', 'n: 2|null'], ModelFixture::classes($output)['App\Dto\C']);
    }

    public function testRejectsATypeThatContradictsTheConst(): void
    {
        $output = ModelFixture::build(['C' => ['type' => 'object', 'properties' => ['s' => ['type' => 'string', 'const' => 5]]]]);

        self::assertSame([self::AT . 'C/properties/s/const: "const" is not of the declared type.'], ModelFixture::messages($output));
    }

    public function testRejectsADefaultOtherThanTheConst(): void
    {
        $output = ModelFixture::build(['C' => ['type' => 'object', 'properties' => ['i' => ['const' => 5, 'default' => 6]]]]);

        self::assertSame([self::AT . 'C/properties/i/default: Default 6 does not match 5; null is used instead.'], ModelFixture::messages($output));
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
}
