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

    public function testLetsAnOpenClassAcceptTheValuesOfItsSubclasses(): void
    {
        $output = ModelFixture::build([
            'Pet' => ['oneOf' => [['$ref' => '#/components/schemas/Cat'], ['$ref' => '#/components/schemas/Dog']], 'discriminator' => ['propertyName' => 'kind']],
            'Cat' => ['type' => 'object', 'properties' => ['kind' => ['type' => 'string']]],
            'Dog' => ['allOf' => [['$ref' => '#/components/schemas/Cat'], ['properties' => ['bark' => []]]]],
        ]);

        self::assertSame([], ModelFixture::messages($output));
        // Dog's constructor passes its own value up, so Cat accepts it too.
        self::assertSame(['App\Dto\Cat' => ["kind: 'Cat'; subclasses: 'Dog'"], 'App\Dto\Dog' => ["kind: 'Dog'"]], ModelFixture::selections($output));
    }

    public function testChecksAnOpenClassOfAnInheritedDiscriminator(): void
    {
        $output = ModelFixture::build([
            'Animal' => [
                'type' => 'object',
                'required' => ['kind'],
                'properties' => ['kind' => ['type' => 'string'], 'name' => ['type' => 'string']],
                'discriminator' => [
                    'propertyName' => 'kind',
                    'mapping' => ['bird' => '#/components/schemas/Bird', 'parrot' => '#/components/schemas/Parrot', 'fish' => '#/components/schemas/Fish'],
                ],
            ],
            'Bird' => ['allOf' => [['$ref' => '#/components/schemas/Animal'], ['required' => ['wings'], 'properties' => ['wings' => ['type' => 'integer']]]]],
            'Parrot' => ['allOf' => [['$ref' => '#/components/schemas/Bird'], ['properties' => ['words' => ['type' => 'integer']]]]],
            'Fish' => ['allOf' => [['$ref' => '#/components/schemas/Animal'], ['required' => ['fins'], 'properties' => ['fins' => ['type' => 'integer']]]]],
        ]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(
            ['App\Dto\Bird' => ["kind: 'bird'; subclasses: 'parrot'"], 'App\Dto\Parrot' => ["kind: 'parrot'"], 'App\Dto\Fish' => ["kind: 'fish'"]],
            ModelFixture::selections($output),
        );
    }

    public function testComparesNoSubclassValueTheTypeAlreadyRulesOut(): void
    {
        $kind = ['type' => 'object', 'required' => ['kind'], 'properties' => ['kind' => ['$ref' => '#/components/schemas/Kind']]];
        $output = ModelFixture::build([
            'Kind' => ['type' => 'string', 'enum' => ['dog', 'puppy']],
            'Pet' => [
                'oneOf' => [['$ref' => '#/components/schemas/Dog'], ['$ref' => '#/components/schemas/Puppy']],
                'discriminator' => ['propertyName' => 'kind', 'mapping' => ['dog' => '#/components/schemas/Dog', 'puppy' => '#/components/schemas/Puppy']],
            ],
            'Dog' => $kind,
            'Puppy' => ['allOf' => [['$ref' => '#/components/schemas/Dog'], ['properties' => ['age' => ['type' => 'integer']]]]],
        ]);

        self::assertSame([], ModelFixture::messages($output));
        // A Dog is only a dog; for a subclass instance the enum leaves no other value to compare.
        self::assertSame(['App\Dto\Dog' => ["kind: 'dog'; subclasses: 'puppy' unchecked"], 'App\Dto\Puppy' => ["kind: 'puppy'"]], ModelFixture::selections($output));
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

    public function testDoesNotCheckAnUntypedDiscriminator(): void
    {
        $at = self::AT;
        $output = ModelFixture::build([
            'Loose' => ['oneOf' => [['$ref' => '#/components/schemas/LooseA']], 'discriminator' => ['propertyName' => 't', 'mapping' => ['5' => '#/components/schemas/LooseA']]],
            'LooseA' => ['type' => 'object', 'required' => ['t'], 'properties' => ['t' => []]],
        ]);

        self::assertSame(
            ["warning {$at}LooseA: The constructor of App\\Dto\\LooseA does not check discriminator \"t\": only a string, integer or enum property can be checked."],
            ModelFixture::messages($output),
        );
        self::assertSame([], ModelFixture::selections($output));
    }

    public function testMarksTheDiscriminatedPropertiesOfTheWholeLineage(): void
    {
        $leaf = static fn (string $own): array => ['type' => 'object', 'required' => ['kind', 'sub', $own], 'properties' => ['kind' => ['type' => 'string'], 'sub' => ['type' => 'string'], $own => ['type' => 'string']]];
        $output = ModelFixture::build([
            'Top' => [
                'oneOf' => [['$ref' => '#/components/schemas/Mid'], ['$ref' => '#/components/schemas/Side']],
                'discriminator' => ['propertyName' => 'kind', 'mapping' => ['m' => '#/components/schemas/Mid', 's' => '#/components/schemas/Side']],
            ],
            'Mid' => [
                'oneOf' => [['$ref' => '#/components/schemas/L1'], ['$ref' => '#/components/schemas/L2']],
                'discriminator' => ['propertyName' => 'sub', 'mapping' => ['one' => '#/components/schemas/L1', 'two' => '#/components/schemas/L2']],
            ],
            'L1' => $leaf('a'),
            'L2' => $leaf('b'),
            'Side' => ['type' => 'object', 'required' => ['c'], 'properties' => ['kind' => ['type' => 'string'], 'c' => ['type' => 'string']]],
            'Flag' => ['oneOf' => [['$ref' => '#/components/schemas/On']], 'discriminator' => ['propertyName' => 'state']],
            'On' => ['type' => 'object', 'required' => ['state'], 'properties' => ['state' => ['type' => 'boolean']]],
        ]);

        $discriminated = [];
        foreach ($output->classes() as $class) {
            $discriminated[$class->model()->name()->shortName()] = $class->model()->discriminatedProperties();
        }

        // Mid keeps `kind`, which Top reads; On's discriminator cannot be checked, yet it still has no mutator.
        self::assertSame(
            ['Top' => ['kind', 'sub'], 'Mid' => ['sub', 'kind'], 'L1' => ['kind', 'sub'], 'L2' => ['kind', 'sub'], 'Side' => ['kind'], 'Flag' => ['state'], 'On' => ['state']],
            $discriminated,
        );
    }

    public function testWarnsAboutAValueAConstPropertyCannotHold(): void
    {
        $at = self::AT;
        $output = ModelFixture::build([
            'Card' => ['oneOf' => [['$ref' => '#/components/schemas/Visa']], 'discriminator' => ['propertyName' => 'brand', 'mapping' => ['amex' => '#/components/schemas/Visa']]],
            'Visa' => ['type' => 'object', 'required' => ['brand'], 'properties' => ['brand' => ['const' => 'visa']]],
        ]);

        self::assertSame(
            ["warning {$at}Visa: Discriminator value \"amex\" is not a value of property \"brand\", so it does not select App\\Dto\\Visa."],
            ModelFixture::messages($output),
        );
        self::assertSame([], ModelFixture::selections($output));
    }
}
