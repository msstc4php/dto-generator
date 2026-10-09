<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Application\Service\Model;

use MSSTC4PHP\DtoGenerator\Tests\Support\ModelFixture;
use PHPUnit\Framework\TestCase;

final class SelectingValuesTest extends TestCase
{
    private const AT = '/project/api/openapi.yaml#/components/schemas/';

    public function testSelectsAVariantByItsMappingAndAnotherByItsName(): void
    {
        $kind = ['type' => 'object', 'required' => ['petType'], 'properties' => ['petType' => ['type' => 'string']]];
        $output = ModelFixture::build([
            'Pet' => [
                'oneOf' => [['$ref' => '#/components/schemas/Cat'], ['$ref' => '#/components/schemas/Dog']],
                'discriminator' => ['propertyName' => 'petType', 'mapping' => ['cat' => '#/components/schemas/Cat', 'kitty' => '#/components/schemas/Cat']],
            ],
            'Cat' => $kind,
            'Dog' => $kind,
        ]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(['App\Dto\Cat' => ["petType: 'cat'|'kitty'"], 'App\Dto\Dog' => ["petType: 'Dog'"]], ModelFixture::selections($output));
    }

    public function testChecksEveryDiscriminatorOfANestedHierarchy(): void
    {
        $breed = ['type' => 'object', 'required' => ['kind', 'breed'], 'properties' => ['kind' => ['type' => 'string'], 'breed' => ['type' => 'string']]];
        $output = ModelFixture::build([
            'Pet' => [
                'oneOf' => [['$ref' => '#/components/schemas/Cat'], ['$ref' => '#/components/schemas/Dog']],
                'discriminator' => ['propertyName' => 'kind', 'mapping' => ['cat' => '#/components/schemas/Cat', 'kitty' => '#/components/schemas/Cat']],
            ],
            'Cat' => [
                'oneOf' => [['$ref' => '#/components/schemas/Siamese'], ['$ref' => '#/components/schemas/Persian']],
                'discriminator' => ['propertyName' => 'breed'],
            ],
            'Siamese' => $breed,
            'Persian' => $breed,
            'Dog' => ['type' => 'object', 'required' => ['kind'], 'properties' => ['kind' => ['type' => 'string']]],
        ]);

        self::assertSame([], ModelFixture::messages($output));
        foreach ($output->classes() as $class) {
            $values = $class->model()->discriminatorValues();
            self::assertSame(array_values($values), $values);
        }

        self::assertSame(
            [
                'App\Dto\Siamese' => ["kind: 'cat'|'kitty'", "breed: 'Siamese'"],
                'App\Dto\Persian' => ["kind: 'cat'|'kitty'", "breed: 'Persian'"],
                'App\Dto\Dog' => ["kind: 'Dog'"],
            ],
            ModelFixture::selections($output),
        );
    }

    public function testIgnoresTheValueOfAnOpenAncestor(): void
    {
        $output = ModelFixture::build([
            'Pet' => ['oneOf' => [['$ref' => '#/components/schemas/Cat'], ['$ref' => '#/components/schemas/Dog']], 'discriminator' => ['propertyName' => 'kind']],
            'Cat' => ['type' => 'object', 'properties' => ['kind' => ['type' => 'string']]],
            'Dog' => ['allOf' => [['$ref' => '#/components/schemas/Cat'], ['properties' => ['bark' => []]]]],
        ]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(['App\Dto\Dog' => ["kind: 'Dog'"]], ModelFixture::selections($output));
    }

    public function testLetsTheNearestDiscriminatorOfAPropertyDecide(): void
    {
        $k = ['type' => 'object', 'required' => ['k'], 'properties' => ['k' => ['type' => 'string']]];
        $output = ModelFixture::build([
            'B0' => ['oneOf' => [['$ref' => '#/components/schemas/B1'], ['$ref' => '#/components/schemas/X']], 'discriminator' => ['propertyName' => 'k']],
            'B1' => ['oneOf' => [['$ref' => '#/components/schemas/Y'], ['$ref' => '#/components/schemas/Z']], 'discriminator' => ['propertyName' => 'k']],
            'X' => $k,
            'Y' => $k,
            'Z' => $k,
        ]);

        self::assertSame(['App\Dto\X' => ["k: 'X'"], 'App\Dto\Y' => ["k: 'Y'"], 'App\Dto\Z' => ["k: 'Z'"]], ModelFixture::selections($output));
    }

    public function testTypesTheValuesAsThePropertyHoldsThem(): void
    {
        $output = ModelFixture::build([
            'Kind' => ['type' => 'string', 'enum' => ['cat', 'dog']],
            'Pet' => [
                'oneOf' => [['$ref' => '#/components/schemas/Cat'], ['$ref' => '#/components/schemas/Dog']],
                'discriminator' => ['propertyName' => 'kind', 'mapping' => ['cat' => '#/components/schemas/Cat', 'dog' => '#/components/schemas/Dog']],
            ],
            'Cat' => ['type' => 'object', 'required' => ['kind'], 'properties' => ['kind' => ['$ref' => '#/components/schemas/Kind']]],
            'Dog' => ['type' => 'object', 'required' => ['kind'], 'properties' => ['kind' => ['$ref' => '#/components/schemas/Kind']]],
            'Coin' => ['oneOf' => [['$ref' => '#/components/schemas/One']], 'discriminator' => ['propertyName' => 'value', 'mapping' => ['1' => '#/components/schemas/One']]],
            'One' => ['type' => 'object', 'required' => ['value'], 'properties' => ['value' => ['type' => 'integer']]],
        ]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(
            ['App\Dto\Cat' => ["kind: 'cat'"], 'App\Dto\Dog' => ["kind: 'dog'"], 'App\Dto\One' => ['value: 1']],
            ModelFixture::selections($output),
        );
    }

    public function testLeavesValuesTheTypeAlreadyGuaranteesUnchecked(): void
    {
        $output = ModelFixture::build([
            'Only' => ['type' => 'string', 'enum' => ['cat']],
            'Pet' => ['oneOf' => [['$ref' => '#/components/schemas/Cat']], 'discriminator' => ['propertyName' => 'kind', 'mapping' => ['cat' => '#/components/schemas/Cat']]],
            'Cat' => ['type' => 'object', 'required' => ['kind'], 'properties' => ['kind' => ['$ref' => '#/components/schemas/Only']]],
            'Card' => ['oneOf' => [['$ref' => '#/components/schemas/Visa']], 'discriminator' => ['propertyName' => 'brand', 'mapping' => ['visa' => '#/components/schemas/Visa']]],
            'Visa' => ['type' => 'object', 'required' => ['brand'], 'properties' => ['brand' => ['const' => 'visa']]],
            'Maybe' => ['oneOf' => [['$ref' => '#/components/schemas/Some']], 'discriminator' => ['propertyName' => 'kind', 'mapping' => ['cat' => '#/components/schemas/Some']]],
            'Some' => ['type' => 'object', 'properties' => ['kind' => ['$ref' => '#/components/schemas/Only']]],
        ]);

        self::assertSame([], ModelFixture::messages($output));
        // A nullable property still needs the check: null selects nothing.
        self::assertSame(
            ['App\Dto\Cat' => ["kind: 'cat' unchecked"], 'App\Dto\Visa' => ["brand: 'visa' unchecked"], 'App\Dto\Some' => ["kind: 'cat'"]],
            ModelFixture::selections($output),
        );
    }

    public function testWarnsAboutValuesThePropertyCannotHold(): void
    {
        $at = self::AT;
        $output = ModelFixture::build([
            'Kind' => ['type' => 'string', 'enum' => ['cat']],
            'Pet' => [
                'oneOf' => [['$ref' => '#/components/schemas/Cat']],
                'discriminator' => ['propertyName' => 'kind', 'mapping' => ['puma' => '#/components/schemas/Cat', 'cat' => '#/components/schemas/Cat']],
            ],
            'Cat' => ['type' => 'object', 'required' => ['kind'], 'properties' => ['kind' => ['$ref' => '#/components/schemas/Kind']]],
            'Coin' => ['oneOf' => [['$ref' => '#/components/schemas/One'], ['$ref' => '#/components/schemas/Two']], 'discriminator' => ['propertyName' => 'value', 'mapping' => ['1' => '#/components/schemas/One']]],
            'One' => ['type' => 'object', 'required' => ['value'], 'properties' => ['value' => ['type' => 'integer']]],
            'Two' => ['type' => 'object', 'required' => ['value'], 'properties' => ['value' => ['type' => 'integer']]],
        ]);

        self::assertSame(
            [
                "warning {$at}Cat: Discriminator value \"puma\" is not a value of property \"kind\", so it does not select App\\Dto\\Cat.",
                "warning {$at}Two: Discriminator value \"Two\" is not a value of property \"value\", so it does not select App\\Dto\\Two.",
            ],
            ModelFixture::messages($output),
        );
        self::assertSame(['App\Dto\Cat' => ["kind: 'cat' unchecked"], 'App\Dto\One' => ['value: 1']], ModelFixture::selections($output));
    }

    public function testDoesNotCheckADiscriminatorOfAnotherType(): void
    {
        $at = self::AT;
        $output = ModelFixture::build([
            'Flag' => ['oneOf' => [['$ref' => '#/components/schemas/On']], 'discriminator' => ['propertyName' => 'state']],
            'On' => ['type' => 'object', 'required' => ['state'], 'properties' => ['state' => ['type' => 'boolean']]],
        ]);

        self::assertSame(
            ["warning {$at}On: The constructor of App\\Dto\\On does not check discriminator \"state\": only a string, integer or enum property can be checked."],
            ModelFixture::messages($output),
        );
        self::assertSame([], ModelFixture::selections($output));
    }

    public function testChecksNothingForAVariantTheMappingLeavesWithoutValue(): void
    {
        $output = ModelFixture::build([
            'Pet' => [
                'oneOf' => [['$ref' => '#/components/schemas/Cat'], ['$ref' => '#/components/schemas/Dog']],
                'discriminator' => ['propertyName' => 'kind', 'mapping' => ['Cat' => 'Dog']],
            ],
            'Cat' => ['type' => 'object', 'properties' => ['kind' => ['type' => 'string']]],
            'Dog' => ['type' => 'object', 'properties' => ['kind' => ['type' => 'string']]],
        ]);

        self::assertCount(1, ModelFixture::messages($output));
        self::assertSame(['App\Dto\Dog' => ["kind: 'Cat'"]], ModelFixture::selections($output));
    }

    public function testKeepsANumericKeyAStringForAStringProperty(): void
    {
        $output = ModelFixture::build([
            'Pet' => ['oneOf' => [['$ref' => '#/components/schemas/Cat']], 'discriminator' => ['propertyName' => 'kind', 'mapping' => ['1' => '#/components/schemas/Cat']]],
            'Cat' => ['type' => 'object', 'required' => ['kind'], 'properties' => ['kind' => ['type' => 'string']]],
        ]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(['App\Dto\Cat' => ["kind: '1'"]], ModelFixture::selections($output));
    }
}
