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

    public function testAcceptsTheValueOfEveryAncestorThatMapsTheClassItself(): void
    {
        $at = static fn (string $name): string => '#/components/schemas/' . $name;
        $output = ModelFixture::build([
            'Animal' => ['type' => 'object', 'properties' => ['petType' => ['type' => 'string']], 'discriminator' => ['propertyName' => 'petType', 'mapping' => ['cat' => $at('Cat'), 'feline' => $at('Feline')]]],
            'Feline' => ['allOf' => [['$ref' => $at('Animal')], ['type' => 'object', 'properties' => ['claws' => ['type' => 'integer']]]], 'discriminator' => ['propertyName' => 'petType', 'mapping' => ['Cat' => $at('Cat')]]],
            'Cat' => ['allOf' => [['$ref' => $at('Feline')], ['type' => 'object', 'properties' => ['lives' => ['type' => 'integer']]]]],
        ]);

        self::assertSame([], ModelFixture::messages($output));
        // Animal reads "cat" and builds a Cat as much as Feline reads "Cat"; with two values the constructor has no default.
        self::assertSame(['App\Dto\Cat' => ["petType: 'Cat'|'cat'"]], ModelFixture::selections($output));
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

    public function testGivesAVariantTheMappingLeavesOutTheOneValueItsPropertyAllows(): void
    {
        $at = self::AT;
        $role = static fn (array $schema): array => ['type' => 'object', 'required' => ['role'], 'properties' => ['role' => $schema]];
        $output = ModelFixture::build([
            'Message' => [
                'oneOf' => [['$ref' => '#/components/schemas/Developer'], ['$ref' => '#/components/schemas/System'], ['$ref' => '#/components/schemas/Free'], ['$ref' => '#/components/schemas/Taken']],
                'discriminator' => ['propertyName' => 'role', 'mapping' => ['user' => '#/components/schemas/Free']],
            ],
            'Developer' => $role(['type' => 'string', 'enum' => ['developer']]),
            'System' => $role(['$ref' => '#/components/schemas/SystemRole']),
            'SystemRole' => ['const' => 'system'],
            'Free' => $role(['type' => 'string']),
            'Taken' => $role(['type' => 'string', 'enum' => ['user']]),
            'Animal' => ['type' => 'object', 'required' => ['kind'], 'properties' => ['kind' => ['type' => 'string']], 'discriminator' => ['propertyName' => 'kind']],
            'Dog' => ['allOf' => [['$ref' => '#/components/schemas/Animal'], ['type' => 'object', 'required' => ['kind'], 'properties' => ['kind' => ['type' => 'string', 'enum' => ['dog']]]]]],
        ]);

        self::assertSame(
            [
                "warning {$at}Dog/allOf/1/properties/kind: Property \"kind\" of App\\Dto\\Dog narrows the one App\\Dto\\Animal declares, whose type string the class keeps.",
                "warning {$at}Taken: Discriminator value \"Taken\" is not a value of property \"role\", so it does not select App\\Dto\\Taken.",
            ],
            ModelFixture::messages($output),
        );
        self::assertSame('abstract by role {user: App\\Dto\\Free, developer: App\\Dto\\Developer, system: App\\Dto\\System, Taken: App\\Dto\\Taken}', ModelFixture::hierarchy($output)['App\\Dto\\Message']);
        self::assertSame(
            ['App\\Dto\\Developer' => ["role: 'developer' unchecked"], 'App\\Dto\\System' => ["role: 'system' unchecked"], 'App\\Dto\\Free' => ["role: 'user'"], 'App\\Dto\\Dog' => ["kind: 'dog'"]],
            ModelFixture::selections($output),
        );
    }

    /**
     * @dataProvider claims
     *
     * @param array<string, array<array-key, mixed>> $variants
     * @param list<string> $messages
     * @param array<string, array<array-key, mixed>> $others schemas that are no variants
     */
    public function testGivesTheOneValueOnlyWhenNoOtherVariantClaimsIt(array $variants, string $mapping, array $messages, array $others = []): void
    {
        $at = self::AT;
        $members = array_map(static fn ($name, array $schema): array => is_int($name) ? $schema : ['$ref' => '#/components/schemas/' . $name], array_keys($variants), $variants);
        $output = ModelFixture::build(['U' => ['oneOf' => $members, 'discriminator' => ['propertyName' => 'role']]] + array_filter($variants, 'is_string', ARRAY_FILTER_USE_KEY) + $others);

        self::assertSame(array_map(static fn (string $message): string => str_replace('@', $at, $message), $messages), ModelFixture::messages($output));
        self::assertSame("abstract by role {{$mapping}}", ModelFixture::hierarchy($output)['App\\Dto\\U']);
    }

    /**
     * @return iterable<string, array{0: array<array-key, array<array-key, mixed>>, 1: string, 2: list<string>, 3?: array<string, array<array-key, mixed>>}>
     */
    public static function claims(): iterable
    {
        $role = static fn (array $schema): array => ['type' => 'object', 'required' => ['role'], 'properties' => ['role' => $schema]];
        $free = $role(['type' => 'string']);

        yield 'the name of a later variant' => [['A' => $role(['enum' => ['B']]), 'B' => $free], 'A: App\\Dto\\A, B: App\\Dto\\B', ['warning @A: Discriminator value "A" is not a value of property "role", so it does not select App\\Dto\\A.']];
        yield 'the name of an earlier variant' => [['B' => $free, 'A' => $role(['enum' => ['B']])], 'B: App\\Dto\\B, A: App\\Dto\\A', ['warning @A: Discriminator value "A" is not a value of property "role", so it does not select App\\Dto\\A.']];
        yield 'the value of a later inline variant' => [['A' => $role(['enum' => ['x']]), 0 => $role(['const' => 'x'])], 'A: App\\Dto\\A, x: App\\Dto\\UOption2', ['warning @A: Discriminator value "A" is not a value of property "role", so it does not select App\\Dto\\A.']];
        yield 'the value of an earlier inline variant' => [[0 => $role(['const' => 'x']), 'A' => $role(['enum' => ['x']])], 'x: App\\Dto\\UOption1, A: App\\Dto\\A', ['warning @A: Discriminator value "A" is not a value of property "role", so it does not select App\\Dto\\A.']];
        yield 'the value of another variant' => [['A' => $role(['enum' => ['x']]), 'B' => $role(['const' => 'x'])], 'A: App\\Dto\\A, B: App\\Dto\\B', [
            'warning @A: Discriminator value "A" is not a value of property "role", so it does not select App\\Dto\\A.',
            'warning @B: Discriminator value "B" is not a value of property "role", so it does not select App\\Dto\\B.',
        ]];
        yield 'a value another variant gives up' => [['A' => $role(['const' => 'B']), 'B' => $role(['const' => 'x'])], 'B: App\\Dto\\A, x: App\\Dto\\B', []];
        yield 'swapped values' => [['A' => $role(['const' => 'B']), 'B' => $role(['const' => 'A'])], 'B: App\\Dto\\A, A: App\\Dto\\B', []];
        yield 'a name an earlier inline variant gives' => [[0 => $role(['const' => 'A']), 'A' => $free], 'A: App\\Dto\\UOption1', ['warning @U/discriminator: Variant App\\Dto\\A gets no discriminator value: another variant already has "A".']];
        yield 'a name a later inline variant gives' => [['A' => $free, 0 => $role(['const' => 'A'])], 'A: App\\Dto\\UOption2', ['warning @U/discriminator: Variant App\\Dto\\A gets no discriminator value: another variant already has "A".']];
        yield 'a nullable value' => [['A' => $role(['anyOf' => [['type' => 'string', 'const' => 'a'], ['type' => 'null']]])], 'a: App\\Dto\\A', []];
        yield 'a value beside a $ref' => [['A' => $role(['$ref' => '#/components/schemas/Role', 'const' => 'a'])], 'a: App\\Dto\\A', [], ['Role' => ['type' => 'string']]];
        yield 'two values in branches' => [['A' => $role(['anyOf' => [['const' => 'a'], ['const' => 'b']]])], 'A: App\\Dto\\A', ['warning @A: The constructor of App\\Dto\\A does not check discriminator "role": only a string, integer or enum property can be checked.']];
        yield 'a value in oneOf branches' => [['A' => $role(['type' => 'string', 'oneOf' => [['const' => 'a'], ['type' => 'null']]])], 'a: App\\Dto\\A', []];
        yield 'one value in two branches' => [['A' => $role(['type' => 'string', 'anyOf' => [['const' => 'a'], ['enum' => ['a']]]])], 'a: App\\Dto\\A', ['warning @A: The constructor of App\\Dto\\A does not check discriminator "role": only a string, integer or enum property can be checked.']];
        yield 'a value beside another variant' => [['A' => ['allOf' => [['$ref' => '#/components/schemas/B'], $role(['type' => 'string', 'const' => 'a'])]], 'B' => $free], 'a: App\\Dto\\A, B: App\\Dto\\B', ['warning @A/allOf/1/properties/role: Property "role" of App\\Dto\\A narrows the one App\\Dto\\B declares, whose type string the class keeps.']];
        yield 'a cascade' => [['A' => $role(['const' => 'x']), 'B' => $role(['const' => 'x']), 'C' => $role(['const' => 'A'])], 'A: App\\Dto\\A, B: App\\Dto\\B, C: App\\Dto\\C', [
            'warning @A: Discriminator value "A" is not a value of property "role", so it does not select App\\Dto\\A.',
            'warning @B: Discriminator value "B" is not a value of property "role", so it does not select App\\Dto\\B.',
            'warning @C: Discriminator value "C" is not a value of property "role", so it does not select App\\Dto\\C.',
        ]];
        yield 'a cascade the other way round' => [['C' => $role(['const' => 'A']), 'B' => $role(['const' => 'x']), 'A' => $role(['const' => 'x'])], 'C: App\\Dto\\C, B: App\\Dto\\B, A: App\\Dto\\A', [
            'warning @C: Discriminator value "C" is not a value of property "role", so it does not select App\\Dto\\C.',
            'warning @B: Discriminator value "B" is not a value of property "role", so it does not select App\\Dto\\B.',
            'warning @A: Discriminator value "A" is not a value of property "role", so it does not select App\\Dto\\A.',
        ]];
        yield 'an empty value and any string in branches' => [['A' => $role(['type' => 'string', 'anyOf' => [['const' => ''], ['type' => 'string']]])], 'A: App\\Dto\\A', []];
        yield 'any string and an empty value in branches' => [['A' => $role(['type' => 'string', 'anyOf' => [['type' => 'string'], ['const' => '']]])], 'A: App\\Dto\\A', []];
        yield 'null before the value in branches' => [['A' => $role(['type' => 'string', 'oneOf' => [['type' => 'null'], ['const' => 'a']]])], 'a: App\\Dto\\A', []];
        yield 'a nullable $ref without a value in branches' => [['A' => $role(['anyOf' => [['const' => 'a'], ['$ref' => '#/components/schemas/Role', 'type' => ['null']]]])], 'A: App\\Dto\\A', [], ['Role' => ['type' => 'string']]];
        yield 'its own name' => [['A' => $role(['enum' => ['A']])], 'A: App\\Dto\\A', []];
        yield 'an integer' => [['A' => $role(['type' => 'integer', 'enum' => [1]]), 'B' => $role(['type' => 'integer', 'enum' => [2]])], '1: App\\Dto\\A, 2: App\\Dto\\B', []];
        yield 'null beside the value' => [['A' => $role(['type' => ['string', 'null'], 'enum' => ['a', null]])], 'a: App\\Dto\\A', []];
        yield 'a described $ref' => [['A' => $role(['allOf' => [['$ref' => '#/components/schemas/Role']], 'description' => 'The role.'])], 'a: App\\Dto\\A', [], ['Role' => ['type' => 'string', 'enum' => ['a']]]];
    }

    public function testLeavesAParentItsValueAndASubclassWithoutOneItsName(): void
    {
        $output = ModelFixture::build([
            'Animal' => ['type' => 'object', 'required' => ['kind'], 'properties' => ['kind' => ['type' => 'string']], 'discriminator' => ['propertyName' => 'kind']],
            'Dog' => ['allOf' => [['$ref' => '#/components/schemas/Animal'], ['type' => 'object', 'required' => ['kind'], 'properties' => ['kind' => ['type' => 'string', 'enum' => ['dog']]]]]],
            'Puppy' => ['allOf' => [['$ref' => '#/components/schemas/Dog'], ['type' => 'object', 'properties' => ['age' => ['type' => 'integer']]]]],
        ]);

        self::assertSame('abstract by kind {dog: App\\Dto\\Dog, Puppy: App\\Dto\\Puppy}', ModelFixture::hierarchy($output)['App\\Dto\\Animal']);
        self::assertSame(['warning ' . self::AT . 'Dog/allOf/1/properties/kind: Property "kind" of App\\Dto\\Dog narrows the one App\\Dto\\Animal declares, whose type string the class keeps.'], ModelFixture::messages($output));
        self::assertSame(['App\\Dto\\Dog' => ["kind: 'dog'; subclasses: 'Puppy'"], 'App\\Dto\\Puppy' => ["kind: 'Puppy'"]], ModelFixture::selections($output));
    }

    public function testGivesAVariantTheNameOfAMappedOne(): void
    {
        $role = static fn (array $schema): array => ['type' => 'object', 'required' => ['role'], 'properties' => ['role' => $schema]];
        $output = ModelFixture::build([
            'U' => ['oneOf' => [['$ref' => '#/components/schemas/A'], ['$ref' => '#/components/schemas/B']], 'discriminator' => ['propertyName' => 'role', 'mapping' => ['b' => '#/components/schemas/B']]],
            'A' => $role(['const' => 'B']),
            'B' => $role(['type' => 'string']),
        ]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame('abstract by role {b: App\\Dto\\B, B: App\\Dto\\A}', ModelFixture::hierarchy($output)['App\\Dto\\U']);
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

    public function testRestoresTheMutatorsASiblingOfADiscriminatedBranchInherits(): void
    {
        $output = ModelFixture::build([
            'Named' => ['type' => 'object', 'required' => ['kind', 'name'], 'properties' => ['kind' => ['type' => 'string'], 'name' => ['type' => 'string']]],
            'Pet' => [
                'allOf' => [['$ref' => '#/components/schemas/Named'], ['type' => 'object', 'properties' => ['age' => ['type' => 'integer']]]],
                'discriminator' => ['propertyName' => 'kind', 'mapping' => ['cat' => '#/components/schemas/Cat']],
            ],
            'Cat' => ['allOf' => [['$ref' => '#/components/schemas/Pet'], ['type' => 'object', 'properties' => ['meow' => ['type' => 'string']]]]],
            'Product' => ['allOf' => [['$ref' => '#/components/schemas/Named'], ['type' => 'object', 'properties' => ['price' => ['type' => 'integer']]]]],
        ]);

        $restored = [];
        foreach ($output->classes() as $class) {
            $restored[$class->model()->name()->shortName()] = [$class->model()->discriminatedProperties(), $class->model()->restoredMutators()];
        }

        // Named is in Pet's chain, so it has no mutator for `kind`; Product, outside it, declares its own.
        self::assertSame(
            ['Named' => [['kind'], []], 'Pet' => [['kind'], []], 'Cat' => [['kind'], []], 'Product' => [[], ['kind']]],
            $restored,
        );
    }

    public function testCollectsTheValuesOfEverySubclassOfAnOpenClass(): void
    {
        $sub = static fn (string $own): array => ['allOf' => [['$ref' => '#/components/schemas/Bird'], ['properties' => [$own => ['type' => 'integer']]]]];
        $output = ModelFixture::build([
            'Animal' => [
                'type' => 'object',
                'required' => ['kind'],
                'properties' => ['kind' => ['type' => 'string']],
                'discriminator' => [
                    'propertyName' => 'kind',
                    'mapping' => ['bird' => '#/components/schemas/Bird', 'parrot' => '#/components/schemas/Parrot', 'macaw' => '#/components/schemas/Macaw'],
                ],
            ],
            'Bird' => ['allOf' => [['$ref' => '#/components/schemas/Animal'], ['properties' => ['wings' => ['type' => 'integer']]]]],
            'Parrot' => $sub('words'),
            'Macaw' => $sub('colors'),
        ]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(["kind: 'bird'; subclasses: 'parrot'|'macaw'"], ModelFixture::selections($output)['App\Dto\Bird']);
    }

    public function testRestoresOnlyTheMutatorsOfInheritedProperties(): void
    {
        $output = ModelFixture::build([
            'Named' => ['type' => 'object', 'required' => ['kind'], 'properties' => ['kind' => ['type' => 'string']]],
            'Pet' => [
                'allOf' => [['$ref' => '#/components/schemas/Named'], ['type' => 'object', 'required' => ['species'], 'properties' => ['species' => ['type' => 'string']]]],
                'discriminator' => ['propertyName' => 'species', 'mapping' => ['cat' => '#/components/schemas/Cat']],
            ],
            'Cat' => ['allOf' => [['$ref' => '#/components/schemas/Pet'], ['type' => 'object', 'properties' => ['meow' => ['type' => 'string']]]]],
            'Product' => ['allOf' => [['$ref' => '#/components/schemas/Named'], ['type' => 'object', 'properties' => ['price' => ['type' => 'integer'], 'species' => ['type' => 'string']]]]],
        ]);

        $restored = [];
        foreach ($output->classes() as $class) {
            $restored[$class->model()->name()->shortName()] = [$class->model()->discriminatedProperties(), $class->model()->restoredMutators()];
        }

        // Named lists `species`, which Pet declares: Product declares its own, but inherits none.
        self::assertSame(['species'], $restored['Named'][0]);
        self::assertSame([[], []], $restored['Product']);
    }
}
