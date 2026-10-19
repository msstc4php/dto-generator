<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Application\Service\Model;

use LogicException;
use MSSTC4PHP\DtoGenerator\Application\Service\Model\Build\BuiltEnum;
use MSSTC4PHP\DtoGenerator\Application\Service\Model\Build\Output;
use MSSTC4PHP\DtoGenerator\Domain\Model\PropertyModel;
use MSSTC4PHP\DtoGenerator\Domain\Target\AllOfStrategy;
use MSSTC4PHP\DtoGenerator\Tests\Support\GraphFixture;
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

    public function testMakesOneClassOfTheInlineObjectsOfAPropertyInSeveralMembers(): void
    {
        $at = self::AT;
        $member = static fn (string $a, array $extra = []): array => ['type' => 'object', 'properties' => [
            'owner' => ['type' => 'object', 'properties' => [$a => ['type' => 'string']]] + $extra,
            'tags' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => [$a], 'properties' => [$a => ['type' => 'integer']]]],
            'kind' => ['type' => 'object', 'properties' => [$a => ['type' => 'string']]],
        ]];
        $output = ModelFixture::build([
            'User' => ['allOf' => [$member('a'), $member('b', ['x-php-class-name' => 'Boss']), $member('c', ['title' => 'C']), array_replace_recursive($member('d'), ['properties' => ['kind' => ['type' => 'string']]])]],
        ]);

        self::assertSame(['owner: App\\Dto\\UserOwner|null', 'tags: list<App\\Dto\\UserTagsItem>|null', 'kind: App\\Dto\\UserKind|null'], ModelFixture::classes($output)['App\\Dto\\User']);
        self::assertSame(['a: string|null', 'c: string|null', 'd: string|null'], ModelFixture::classes($output)['App\\Dto\\UserOwner']);
        self::assertSame(['a: int', 'b: int', 'c: int', 'd: int'], ModelFixture::classes($output)['App\\Dto\\UserTagsItem']);
        self::assertSame(['a: string|null', 'b: string|null', 'c: string|null'], ModelFixture::classes($output)['App\\Dto\\UserKind']);
        self::assertSame([
            "error {$at}User/allOf/1/properties/owner: Property \"owner\" of App\\Dto\\User is App\\Dto\\Boss|null here, but App\\Dto\\UserOwner|null in an earlier allOf member.",
            "error {$at}User/allOf/3/properties/kind: Property \"kind\" of App\\Dto\\User is string|null here, but App\\Dto\\UserKind|null in an earlier allOf member.",
        ], ModelFixture::messages($output));
    }

    /**
     * @dataProvider unjoinable
     *
     * @param array<string, mixed> $second the second member's inline object
     * @param array<string, array<array-key, mixed>> $others more schemas
     */
    public function testKeepsApartInlineObjectsSomethingElseTellsApart(array $second, array $others, string $expected): void
    {
        $first = $expected === 'first'
            ? ['oneOf' => [['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]]], ['type' => 'string']]]
            : ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]];
        $output = ModelFixture::build([
            'User' => ['allOf' => [
                ['type' => 'object', 'properties' => ['owner' => $first]],
                ['type' => 'object', 'properties' => ['owner' => $second]],
            ]],
        ] + $others);

        if ($expected === 'first') {
            self::assertSame(['a: string|null'], ModelFixture::classes($output)['App\\Dto\\UserOwnerOption1Item']);
            self::assertSame(['b: string|null'], ModelFixture::classes($output)['App\\Dto\\UserOwnerOption1Item2']);
            self::assertContains('error ' . self::AT . 'User/allOf/1/properties/owner: Property "owner" of App\\Dto\\User is list<App\\Dto\\UserOwnerOption1Item2>|string|null here, but list<App\\Dto\\UserOwnerOption1Item>|string|null in an earlier allOf member.', ModelFixture::messages($output));

            return;
        }

        self::assertContains($expected, ModelFixture::messages($output));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, array<string, array<array-key, mixed>>, string}>
     */
    public static function unjoinable(): iterable
    {
        $at = self::AT;
        $object = ['type' => 'object', 'properties' => ['b' => ['type' => 'string']]];
        $conflict = static fn (string $class): string => "error {$at}User/allOf/1/properties/owner: Property \"owner\" of App\\Dto\\User is App\\Dto\\{$class}|null here, but App\\Dto\\UserOwner|null in an earlier allOf member.";

        yield 'its own allOf' => [$object + ['allOf' => [['$ref' => '#/components/schemas/Mixin']]], ['Mixin' => ['type' => 'object', 'properties' => ['m' => ['type' => 'string']]]], $conflict('UserOwner2')];
        yield 'undeclared properties' => [$object + ['additionalProperties' => ['type' => 'string']], [], $conflict('UserOwner2')];
        yield 'an x- key' => [$object + ['x-dto-mutable' => true], [], $conflict('UserOwner2')];
        yield 'a keyword of the class' => [$object + ['dependentRequired' => ['b' => ['b']]], [], $conflict('UserOwner2')];
        yield 'a union' => [['oneOf' => [$object, ['type' => 'object', 'properties' => ['c' => ['type' => 'string']]]]], [], "error {$at}User/allOf/1/properties/owner: Property \"owner\" of App\\Dto\\User is App\\Dto\\UserOwnerOption1|App\\Dto\\UserOwnerOption2|null here, but App\\Dto\\UserOwner|null in an earlier allOf member."];
        yield 'an item of a union' => [['oneOf' => [['type' => 'array', 'items' => $object], ['type' => 'string']]], [], 'first'];
        yield 'a $ref to the second' => [$object, ['Other' => ['type' => 'object', 'properties' => ['x' => ['$ref' => '#/components/schemas/User/allOf/1/properties/owner']]]], $conflict('Owner')];
        yield 'a $ref to the first' => [$object, ['Other' => ['type' => 'object', 'properties' => ['x' => ['$ref' => '#/components/schemas/User/allOf/0/properties/owner']]]], "error {$at}User/allOf/1/properties/owner: Property \"owner\" of App\\Dto\\User is App\\Dto\\UserOwner|null here, but App\\Dto\\Owner|null in an earlier allOf member."];
    }

    public function testLeavesToTheParentAPropertyTheChildNarrows(): void
    {
        $at = self::AT;
        $output = ModelFixture::build([
            'Pet' => ['type' => 'object', 'properties' => [
                'at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                'n' => ['type' => 'string'],
                'kind' => ['type' => 'string'],
                'size' => ['type' => 'string', 'enum' => ['s', 'm', 'l']],
                'state' => ['type' => 'string', 'enum' => ['open', 'done', 'lost']],
                'mode' => ['type' => ['string', 'null'], 'enum' => ['on', 'off', null]],
            ]],
            'Cat' => ['allOf' => [self::PET, ['type' => 'object', 'required' => ['at'], 'properties' => [
                'at' => ['type' => 'string', 'format' => 'date-time'],
                'n' => ['type' => 'string', 'minLength' => 1],
                'kind' => ['type' => 'string', 'enum' => ['cat']],
                'size' => ['type' => 'string', 'enum' => ['s']],
                'state' => ['type' => ['string', 'null'], 'enum' => ['done', 'lost', 'failed', null]],
                'mode' => ['type' => 'string', 'enum' => ['off', 'auto']],
                'meow' => ['type' => 'string'],
            ]]]],
        ]);

        $narrows = static fn (string $name, string $type): string => "warning {$at}Cat/allOf/1/properties/{$name}: Property \"{$name}\" of App\\Dto\\Cat narrows the one App\\Dto\\Pet declares, whose type {$type} the class keeps.";
        self::assertSame(
            [str_replace(' keeps.', ' keeps and does not require it.', $narrows('at', 'DateTimeImmutable|null')), $narrows('n', 'string|null'), $narrows('kind', 'string|null'), $narrows('size', 'App\\Dto\\PetSize|null'), str_replace('narrows', 'shares values with', $narrows('state', 'App\\Dto\\PetState|null')), str_replace('narrows', 'shares values with', $narrows('mode', 'App\\Dto\\PetMode|null'))],
            array_values(array_filter(ModelFixture::messages($output), static fn (string $message): bool => strpos($message, ' the class keeps') !== false)),
        );
        self::assertSame(['meow: string|null'], ModelFixture::classes($output)['App\\Dto\\Cat']);
    }

    public function testLeavesOutTheInlineTypesItSetsAside(): void
    {
        $output = ModelFixture::build([
            'Pet' => ['type' => 'object', 'properties' => ['kind' => ['type' => 'string']]],
            'Cat' => ['allOf' => [self::PET, ['type' => 'object', 'properties' => ['kind' => ['type' => 'string', 'enum' => ['cat']], 'meow' => ['type' => 'string']]]]],
            'Dog' => ['allOf' => [
                ['type' => 'object', 'properties' => ['state' => ['type' => 'string', 'enum' => ['a', 'b']], 'mode' => ['oneOf' => [['type' => 'string', 'enum' => ['on', 'off']], ['type' => 'integer']]]]],
                ['type' => 'object', 'properties' => ['mode' => ['type' => 'string', 'enum' => ['on']]]],
                ['type' => 'object', 'properties' => ['state' => ['type' => 'string', 'enum' => ['a']], 'tags' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['x']]]]],
                ['type' => 'object', 'properties' => ['tags' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['x', 'y']]]]],
            ]],
        ]);

        self::assertSame(['App\\Dto\\DogMode', 'App\\Dto\\DogState2', 'App\\Dto\\DogTagsItem'], array_map(static fn (BuiltEnum $enum): string => $enum->model()->name()->fqcn(), $output->enums()));
        self::assertSame(['state: App\\Dto\\DogState2|null', 'mode: App\\Dto\\DogMode|null', 'tags: list<App\\Dto\\DogTagsItem>|null'], ModelFixture::classes($output)['App\\Dto\\Dog']);
    }

    public function testGeneratesTheNamedSchemasItSetsAside(): void
    {
        $output = ModelFixture::build([
            'Status' => ['type' => 'string', 'enum' => ['a', 'b', 'c']],
            'Narrow' => ['type' => 'string', 'enum' => ['a']],
            'Kind' => ['type' => 'string', 'enum' => ['k']],
            'Pet' => ['allOf' => [
                ['type' => 'object', 'properties' => ['s' => ['$ref' => '#/components/schemas/Status']]],
                ['type' => 'object', 'properties' => ['s' => ['$ref' => '#/components/schemas/Narrow']]],
            ]],
            'Base' => ['type' => 'object', 'properties' => ['k' => ['type' => 'string']]],
            'Kid' => ['allOf' => [['$ref' => '#/components/schemas/Base'], ['type' => 'object', 'properties' => ['k' => ['$ref' => '#/components/schemas/Kind'], 'n' => ['type' => 'string']]]]],
        ]);

        self::assertSame(['App\\Dto\\Status', 'App\\Dto\\Narrow', 'App\\Dto\\Kind'], array_map(static fn (BuiltEnum $enum): string => $enum->model()->name()->fqcn(), $output->enums()));
    }

    public function testReportsAnObjectItCannotJoinOnlyWhereTheTypesMeet(): void
    {
        $at = self::AT;
        $output = ModelFixture::build(['User' => ['allOf' => [
            ['type' => 'object', 'properties' => ['owner' => ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]]]],
            ['type' => 'object', 'properties' => ['owner' => ['type' => 'object', 'x-dto-mutable' => true, 'properties' => ['b' => ['type' => 'string']]]]],
        ]]]);

        self::assertSame(
            ["error {$at}User/allOf/1/properties/owner: Property \"owner\" of App\\Dto\\User is App\\Dto\\UserOwner2|null here, but App\\Dto\\UserOwner|null in an earlier allOf member."],
            ModelFixture::messages($output),
        );
    }

    /**
     * @dataProvider redeclarations
     *
     * @param array<string, mixed> $parent
     * @param array<string, mixed> $child
     */
    public function testReportsARedeclarationConstraintsCannotCheck(array $parent, array $child): void
    {
        $at = self::AT;
        $output = ModelFixture::build([
            'Pet' => ['type' => 'object', 'properties' => ['p' => $parent]],
            'Cat' => ['allOf' => [self::PET, ['type' => 'object', 'properties' => ['p' => $child]]]],
            'Meta' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]],
        ]);

        self::assertContains("error {$at}Cat/allOf/1/properties/p: Property \"p\" of App\\Dto\\Cat is already declared by App\\Dto\\Pet.", ModelFixture::messages($output));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, array<string, mixed>}>
     */
    public static function redeclarations(): iterable
    {
        yield 'a date over a string' => [['type' => 'string'], ['type' => 'string', 'format' => 'date-time']];
        yield 'an int over a number' => [['type' => 'number'], ['type' => 'integer']];
        yield 'a class over an object' => [['type' => 'object'], ['$ref' => '#/components/schemas/Meta']];
        yield 'a list of narrower items' => [['type' => 'array'], ['type' => 'array', 'items' => ['type' => 'string']]];
        yield 'another default' => [['type' => 'string', 'default' => 'a'], ['type' => 'string', 'default' => 'b']];
        yield 'a default the parent lacks' => [['type' => 'string'], ['type' => 'string', 'default' => 'b']];
        yield 'an enum of other values' => [['type' => 'string', 'enum' => ['a', 'b']], ['type' => 'string', 'enum' => ['c']]];
        yield 'an enum of other ints' => [['type' => 'string', 'enum' => ['1']], ['type' => 'integer', 'enum' => [1, 2]]];
        yield 'another name' => [['type' => 'string'], ['type' => 'string', 'x-php-name' => 'q']];
    }

    public function testMergesAPropertyIntoTheTypeOfTheValuesBothAdmit(): void
    {
        $output = ModelFixture::build([
            'A' => ['type' => 'object', 'required' => ['done', 'id'], 'properties' => [
                'at' => ['type' => 'string'],
                'done' => ['type' => ['string', 'null']],
                'id' => ['type' => 'number'],
                'tags' => ['type' => 'array'],
                'owner' => ['type' => 'object'],
                'kind' => ['oneOf' => [['type' => 'integer'], ['type' => 'string', 'format' => 'date-time']]],
            ]],
            'B' => ['type' => 'object', 'required' => ['done', 'id'], 'properties' => [
                'at' => ['type' => 'string', 'format' => 'date-time'],
                'done' => ['type' => 'string', 'format' => 'date'],
                'id' => ['type' => 'integer'],
                'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
                'owner' => ['type' => 'object', 'properties' => ['n' => ['type' => 'string']]],
                'kind' => ['type' => 'string'],
            ]],
            'C' => ['allOf' => [['$ref' => '#/components/schemas/A'], ['$ref' => '#/components/schemas/B']]],
        ]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(
            ['at: DateTimeImmutable|null', 'done: DateTimeImmutable', 'id: int', 'tags: list<string>|null', 'owner: App\Dto\BOwner|null', 'kind: DateTimeImmutable|null'],
            ModelFixture::classes($output)['App\Dto\C'],
        );
    }

    /**
     * @dataProvider shapes
     *
     * @param array<string, mixed> $wide
     * @param array<string, mixed> $narrow
     */
    public function testMergesAClassIntoOneThatHasItsPropertiesAndMore(array $wide, array $narrow, string $expected): void
    {
        $at = self::AT;
        $output = ModelFixture::build([
            'Ttl' => ['type' => 'string', 'enum' => ['30m']],
            'Wide' => $wide,
            'Narrow' => $narrow,
            'Req' => ['allOf' => [
                ['type' => 'object', 'properties' => ['opts' => ['$ref' => '#/components/schemas/Wide']]],
                ['type' => 'object', 'properties' => ['opts' => ['$ref' => '#/components/schemas/Narrow']]],
            ]],
            'Rev' => ['allOf' => [
                ['type' => 'object', 'properties' => ['opts' => ['$ref' => '#/components/schemas/Narrow']]],
                ['type' => 'object', 'properties' => ['opts' => ['$ref' => '#/components/schemas/Wide']]],
            ]],
        ]);

        if ($expected === 'error') {
            self::assertContains("error {$at}Req/allOf/1/properties/opts: Property \"opts\" of App\\Dto\\Req is App\\Dto\\Narrow|null here, but App\\Dto\\Wide|null in an earlier allOf member.", ModelFixture::messages($output));

            return;
        }

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(["opts: App\\Dto\\{$expected}|null"], ModelFixture::classes($output)['App\\Dto\\Req']);
        self::assertSame(["opts: App\\Dto\\{$expected}|null"], ModelFixture::classes($output)['App\\Dto\\Rev']);
    }

    /**
     * @dataProvider subclasses
     *
     * @param array<string, array<array-key, mixed>> $schemas
     */
    public function testMeetsAClassWithOneWhoseAllOfHasIt(array $schemas, string $strategy, string $expected, string $wide = 'Wide'): void
    {
        $at = self::AT;
        $output = ModelFixture::build($schemas + [
            'Wide' => ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]],
            'Req' => ['allOf' => [
                ['type' => 'object', 'properties' => ['opts' => ['$ref' => '#/components/schemas/Wide']]],
                ['type' => 'object', 'properties' => ['opts' => ['$ref' => '#/components/schemas/Narrow']]],
            ]],
            'Rev' => ['allOf' => [
                ['type' => 'object', 'properties' => ['opts' => ['$ref' => '#/components/schemas/Narrow']]],
                ['type' => 'object', 'properties' => ['opts' => ['$ref' => '#/components/schemas/Wide']]],
            ]],
        ], [], ['*'], $strategy);

        if ($expected === 'error') {
            self::assertContains("error {$at}Req/allOf/1/properties/opts: Property \"opts\" of App\\Dto\\Req is App\\Dto\\Narrow|null here, but App\\Dto\\{$wide}|null in an earlier allOf member.", ModelFixture::messages($output));

            return;
        }

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(["opts: App\\Dto\\{$expected}|null"], ModelFixture::classes($output)['App\\Dto\\Req']);
        self::assertSame(["opts: App\\Dto\\{$expected}|null"], ModelFixture::classes($output)['App\\Dto\\Rev']);
    }

    /**
     * @return iterable<string, array{0: array<string, array<array-key, mixed>>, 1: string, 2: string, 3?: string}>
     */
    public static function subclasses(): iterable
    {
        $more = ['type' => 'object', 'properties' => ['b' => ['type' => 'integer']]];
        $narrow = ['allOf' => [['$ref' => '#/components/schemas/Wide'], $more]];
        $closed = ['type' => 'object', 'additionalProperties' => false, 'properties' => ['a' => ['type' => 'string']]];
        $discriminated = [
            'type' => 'object',
            'required' => ['kind'],
            'properties' => ['kind' => ['type' => 'string']],
            'oneOf' => [['$ref' => '#/components/schemas/A'], ['$ref' => '#/components/schemas/B']],
            'discriminator' => ['propertyName' => 'kind'],
        ];
        $variants = [
            'A' => ['type' => 'object', 'required' => ['kind'], 'properties' => ['kind' => ['type' => 'string', 'enum' => ['A']]]],
            'B' => ['type' => 'object', 'required' => ['kind'], 'properties' => ['kind' => ['type' => 'string', 'enum' => ['B']]]],
        ];

        yield 'a parent' => [['Narrow' => $narrow], AllOfStrategy::EXTENDS, 'Narrow'];
        yield 'a parent of a parent' => [['Narrow' => ['allOf' => [['$ref' => '#/components/schemas/Mid'], $more]], 'Mid' => ['allOf' => [['$ref' => '#/components/schemas/Wide'], ['type' => 'object', 'properties' => ['c' => ['type' => 'integer']]]]]], AllOfStrategy::EXTENDS, 'Narrow'];
        yield 'a parent through an alias' => [['Narrow' => ['allOf' => [['$ref' => '#/components/schemas/Alias'], $more]], 'Alias' => ['$ref' => '#/components/schemas/Wide']], AllOfStrategy::EXTENDS, 'Narrow'];
        yield 'a parent through a skipped wrapper' => [['Narrow' => ['allOf' => [['$ref' => '#/components/schemas/Mid'], $more]], 'Mid' => ['x-php-skip' => true, 'allOf' => [['$ref' => '#/components/schemas/Wide']]]], AllOfStrategy::EXTENDS, 'Narrow'];
        yield 'a parent with a cycle' => [['Narrow' => ['allOf' => [['$ref' => '#/components/schemas/Wide'], ['type' => 'object', 'properties' => ['self' => ['$ref' => '#/components/schemas/Narrow']]]]]], AllOfStrategy::EXTENDS, 'Narrow'];
        yield 'a closed parent' => [['Wide' => $closed, 'Narrow' => $narrow], AllOfStrategy::EXTENDS, 'Narrow'];
        // A merged class keeps only the properties of a member, not its discriminator or other constraints.
        yield 'a merged member' => [['Narrow' => $narrow], AllOfStrategy::MERGE, 'error'];
        yield 'one of several members' => [['Narrow' => ['allOf' => [['$ref' => '#/components/schemas/Base'], ['$ref' => '#/components/schemas/Wide'], $more]], 'Base' => ['type' => 'object', 'properties' => ['z' => ['type' => 'string']]]], AllOfStrategy::EXTENDS, 'error'];
        yield "an inline member's member" => [['Narrow' => ['allOf' => [['allOf' => [['$ref' => '#/components/schemas/Wide']]], $more]]], AllOfStrategy::EXTENDS, 'error'];
        yield 'an unrelated class' => [['Narrow' => ['type' => 'object', 'properties' => ['w' => ['$ref' => '#/components/schemas/Wide']]]], AllOfStrategy::EXTENDS, 'error'];
        yield 'a union of it' => [['Narrow' => $discriminated, 'A' => ['allOf' => [['$ref' => '#/components/schemas/Wide'], $variants['A']]], 'B' => $variants['B']], AllOfStrategy::EXTENDS, 'error'];
        // A variant checks the values that select it, which its schema does not tell.
        yield 'a variant with fewer properties' => [[
            'Wide' => ['type' => 'object', 'required' => ['kind'], 'properties' => ['kind' => ['type' => 'string']]],
            'Narrow' => ['type' => 'object', 'required' => ['kind'], 'properties' => ['kind' => ['type' => 'string'], 'b' => ['type' => 'integer']]],
            'Union' => ['oneOf' => [['$ref' => '#/components/schemas/Wide'], ['$ref' => '#/components/schemas/Other']], 'discriminator' => ['propertyName' => 'kind', 'mapping' => ['w' => '#/components/schemas/Wide', 'o' => '#/components/schemas/Other']]],
            'Other' => ['type' => 'object', 'required' => ['kind'], 'properties' => ['kind' => ['type' => 'string']]],
        ], AllOfStrategy::EXTENDS, 'error'];
        yield 'an anyOf variant with fewer properties' => [[
            'Wide' => ['type' => 'object', 'required' => ['kind'], 'properties' => ['kind' => ['type' => 'string']]],
            'Narrow' => ['type' => 'object', 'required' => ['kind'], 'properties' => ['kind' => ['type' => 'string'], 'b' => ['type' => 'integer']]],
            'Union' => ['anyOf' => [['$ref' => '#/components/schemas/Other'], ['$ref' => '#/components/schemas/Wide']], 'discriminator' => ['propertyName' => 'kind', 'mapping' => ['w' => '#/components/schemas/Wide', 'o' => '#/components/schemas/Other']]],
            'Other' => ['type' => 'object', 'required' => ['kind'], 'properties' => ['kind' => ['type' => 'string']]],
        ], AllOfStrategy::EXTENDS, 'error'];
        $variant = ['type' => 'object', 'required' => ['kind'], 'properties' => ['kind' => ['type' => 'string']]];
        $holding = ['type' => 'object', 'required' => ['kind'], 'properties' => ['kind' => ['type' => 'string'], 'b' => ['type' => 'integer']]];
        yield 'a variant through an alias' => [[
            'Wide' => $variant,
            'Narrow' => $holding,
            'Alias' => ['$ref' => '#/components/schemas/Wide'],
            'Union' => ['oneOf' => [['$ref' => '#/components/schemas/Alias'], ['$ref' => '#/components/schemas/Other']], 'discriminator' => ['propertyName' => 'kind']],
            'Other' => $variant,
        ], AllOfStrategy::EXTENDS, 'error'];
        yield "a nested union's inline variant" => [[
            'Narrow' => ['type' => 'object', 'required' => ['kind', 'type'], 'properties' => ['kind' => ['type' => 'string', 'enum' => ['a']], 'type' => ['type' => 'string'], 'b' => ['type' => 'integer']]],
            'Outer' => ['oneOf' => [['$ref' => '#/components/schemas/Inner'], ['$ref' => '#/components/schemas/Other']], 'discriminator' => ['propertyName' => 'type']],
            'Other' => ['type' => 'object', 'required' => ['type'], 'properties' => ['type' => ['type' => 'string']]],
            'Inner' => ['oneOf' => [
                ['type' => 'object', 'required' => ['kind', 'type'], 'properties' => ['kind' => ['type' => 'string', 'enum' => ['a']], 'type' => ['type' => 'string']]],
                ['type' => 'object', 'required' => ['kind', 'type'], 'properties' => ['kind' => ['type' => 'string', 'enum' => ['w']], 'type' => ['type' => 'string']]],
            ], 'discriminator' => ['propertyName' => 'kind']],
            'Req' => ['allOf' => [
                ['type' => 'object', 'properties' => ['opts' => ['$ref' => '#/components/schemas/Inner/oneOf/0']]],
                ['type' => 'object', 'properties' => ['opts' => ['$ref' => '#/components/schemas/Narrow']]],
            ]],
            'Rev' => ['type' => 'object'],
        ], AllOfStrategy::EXTENDS, 'error', '_0'];
        yield 'a variant of an inline union' => [[
            'Wide' => $variant,
            'Narrow' => $holding,
            'Holder' => ['type' => 'object', 'properties' => ['u' => ['oneOf' => [['$ref' => '#/components/schemas/Wide'], ['$ref' => '#/components/schemas/Other']], 'discriminator' => ['propertyName' => 'kind']]]],
            'Other' => $variant,
        ], AllOfStrategy::EXTENDS, 'Narrow'];
        yield 'a class with fewer properties' => [[
            'Wide' => ['type' => 'object', 'required' => ['kind'], 'properties' => ['kind' => ['type' => 'string']]],
            'Narrow' => ['type' => 'object', 'required' => ['kind'], 'properties' => ['kind' => ['type' => 'string'], 'b' => ['type' => 'integer']]],
        ], AllOfStrategy::EXTENDS, 'Narrow'];
    }

    /**
     * @dataProvider strategies
     */
    public function testWritesAnAllOfOfAUnionOfObjectsAsTheUnionOfItsVariants(string $strategy): void
    {
        $object = static fn (array $properties): array => ['type' => 'object', 'properties' => $properties];
        $output = ModelFixture::build([
            'A' => $object(['a' => ['type' => 'string']]),
            'B' => $object(['b' => ['type' => 'integer']]),
            'Event' => ['anyOf' => [['$ref' => '#/components/schemas/A'], ['$ref' => '#/components/schemas/B']]],
            'Stream' => ['allOf' => [['$ref' => '#/components/schemas/Event'], $object(['stream_id' => ['type' => 'string']])]],
            'Outer' => ['allOf' => [['$ref' => '#/components/schemas/Stream'], $object(['more' => ['type' => 'string']])]],
            'Holder' => $object([
                'stream' => ['$ref' => '#/components/schemas/Stream'],
                'inline' => ['allOf' => [$object(['x' => ['type' => 'string']]), ['$ref' => '#/components/schemas/Event'], ['required' => ['x']]]],
            ]),
            'Named' => [
                'x-php-class-name' => 'Renamed',
                'required' => ['n'],
                'properties' => ['n' => ['type' => 'string']],
                'allOf' => [['oneOf' => [['$ref' => '#/components/schemas/A'], $object(['e' => ['type' => 'string']]) + ['title' => 'Extra'], $object(['o' => ['type' => 'string']])]]],
            ],
        ], [], ['*'], $strategy);
        $extends = $strategy === AllOfStrategy::EXTENDS;

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(
            [
                'App\\Dto\\A' => ['a: string|null'],
                'App\\Dto\\B' => ['b: int|null'],
                'App\\Dto\\Holder' => ['stream: App\\Dto\\StreamA|App\\Dto\\StreamB|null', 'inline: App\\Dto\\HolderInlineA|App\\Dto\\HolderInlineB|null'],
                'App\\Dto\\StreamA' => $extends ? ['stream_id: string|null'] : ['a: string|null', 'stream_id: string|null'],
                'App\\Dto\\StreamB' => $extends ? ['stream_id: string|null'] : ['b: int|null', 'stream_id: string|null'],
                'App\\Dto\\RenamedA' => $extends ? ['n: string'] : ['a: string|null', 'n: string'],
                'App\\Dto\\RenamedExtra' => ['e: string|null', 'n: string'],
                'App\\Dto\\RenamedOption3' => ['o: string|null', 'n: string'],
                'App\\Dto\\OuterStreamA' => $extends ? ['more: string|null'] : ['a: string|null', 'stream_id: string|null', 'more: string|null'],
                'App\\Dto\\OuterStreamB' => $extends ? ['more: string|null'] : ['b: int|null', 'stream_id: string|null', 'more: string|null'],
                'App\\Dto\\HolderInlineA' => $extends ? ['x: string'] : ['x: string', 'a: string|null'],
                'App\\Dto\\HolderInlineB' => $extends ? ['x: string'] : ['x: string', 'b: int|null'],
            ],
            ModelFixture::classes($output),
        );
        if ($extends) {
            $hierarchy = ModelFixture::hierarchy($output);
            self::assertSame('open extends App\\Dto\\A', $hierarchy['App\\Dto\\StreamA']);
            self::assertSame('final extends App\\Dto\\StreamB', $hierarchy['App\\Dto\\OuterStreamB']);
            self::assertSame('final extends App\\Dto\\B', $hierarchy['App\\Dto\\HolderInlineB']);
        }
    }

    public function testNamesWhatVariantsShareAfterTheirSchema(): void
    {
        $object = static fn (array $properties): array => ['type' => 'object', 'properties' => $properties];
        $output = ModelFixture::build([
            'A' => $object(['a' => ['type' => 'string']]),
            'B' => $object(['b' => ['type' => 'integer']]),
            'S' => ['allOf' => [
                ['anyOf' => [['$ref' => '#/components/schemas/A'], $object(['own' => $object(['o' => ['type' => 'string']])])]],
                $object(['meta' => $object(['m' => ['type' => 'string']]), 'kind' => ['type' => 'string', 'enum' => ['x', 'y']]]),
            ]],
        ]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(['meta: App\\Dto\\SMeta|null', 'kind: App\\Dto\\SKind|null'], ModelFixture::classes($output)['App\\Dto\\SA']);
        self::assertSame(['own: App\\Dto\\SOption2Own|null', 'meta: App\\Dto\\SMeta|null', 'kind: App\\Dto\\SKind|null'], ModelFixture::classes($output)['App\\Dto\\SOption2']);
    }

    public function testStopsWhereAUnionLeadsBackToItself(): void
    {
        $at = self::AT;
        $object = static fn (array $properties): array => ['type' => 'object', 'properties' => $properties];
        $ref = static fn (string $name): array => ['$ref' => '#/components/schemas/' . $name];
        $error = 'An allOf member of a class must be an object schema, not a oneOf or anyOf: no class adds properties to each variant.';
        $output = ModelFixture::build([
            'A' => $object(['a' => ['type' => 'string']]),
            'C' => $object(['c' => ['type' => 'string']]),
            'Self' => ['anyOf' => [$ref('Self'), $ref('A')]],
            'OnSelf' => ['allOf' => [$ref('Self'), $object(['x' => ['type' => 'string']])]],
            'V' => ['anyOf' => [$ref('W'), $ref('A')]],
            'W' => ['anyOf' => [$ref('V'), $ref('C')]],
            'OnPair' => ['allOf' => [['anyOf' => [$ref('V'), $object(['y' => ['type' => 'string']])]], $object(['x' => ['type' => 'string']])]],
        ]);

        self::assertSame(["error {$at}OnSelf/allOf/0: {$error}", "error {$at}OnPair/allOf/0/anyOf/0: {$error}"], ModelFixture::messages($output));
        self::assertSame(['App\\Dto\\A', 'App\\Dto\\C', 'App\\Dto\\OnSelf', 'App\\Dto\\OnPairV', 'App\\Dto\\OnPairOption2'], array_keys(ModelFixture::classes($output)));
    }

    public function testNamesWhatVariantsShareAfterTheSchemaTheyComeFromThroughEveryRound(): void
    {
        $at = self::AT;
        $object = static fn (array $properties): array => ['type' => 'object', 'properties' => $properties];
        $ref = static fn (string $name): array => ['$ref' => '#/components/schemas/' . $name];
        $output = ModelFixture::build([
            'A' => $object(['a' => ['type' => 'string']]),
            'B' => $object(['b' => ['type' => 'integer']]),
            'C' => $object(['c' => ['type' => 'string']]),
            'D' => $object(['d' => ['type' => 'string']]),
            'T' => ['allOf' => [['anyOf' => [$ref('C'), $ref('D')]], $object(['t' => ['type' => 'string']])]],
            'S' => ['allOf' => [$ref('T'), ['anyOf' => [$ref('A'), $ref('B')]], $object(['meta' => $object(['m' => ['type' => 'string']])])]],
            'My Thing' => [
                'type' => 'object',
                'properties' => ['meta' => $object(['m' => ['type' => 'string']])],
                'additionalProperties' => $object(['v' => ['type' => 'string']]),
                'allOf' => [['anyOf' => [$ref('A'), $ref('B')]]],
            ],
            'SA' => $object(['z' => ['type' => 'string']]),
            'Taken' => ['allOf' => [['anyOf' => [$ref('A'), $ref('B')]], $object(['x' => ['type' => 'string']])], 'x-php-class-name' => 'S'],
        ]);
        $classes = ModelFixture::classes($output);

        self::assertSame(["error {$at}Taken/x-dto-variant/0/0: Class App\\Dto\\SA is already generated from {$at}SA; set \"x-php-class-name\" on one of them (on the schema this one is distributed from or on a referenced variant, or a \"title\" on an inline one)."], ModelFixture::messages($output));
        foreach (['SATC', 'SATD', 'SBTC', 'SBTD'] as $variant) {
            self::assertContains('meta: App\\Dto\\SMeta|null', $classes['App\\Dto\\' . $variant]);
        }

        foreach (['MyThingA', 'MyThingB'] as $variant) {
            self::assertSame(['meta: App\\Dto\\MyThingMeta|null', 'additionalProperties: array<array-key, App\\Dto\\MyThingAdditionalProperty>'], $classes['App\\Dto\\' . $variant]);
        }
    }

    public function testNamesEveryClassOfANestedDistributionOnce(): void
    {
        $object = static fn (array $properties): array => ['type' => 'object', 'properties' => $properties];
        $ref = static fn (string $name): array => ['$ref' => '#/components/schemas/' . $name];
        $output = ModelFixture::build([
            'A' => $object(['a' => ['type' => 'string']]),
            'B' => $object(['b' => ['type' => 'string']]),
            'C' => $object(['c' => ['type' => 'string']]),
            'D' => $object(['d' => ['type' => 'string']]),
            'U1' => ['anyOf' => [$ref('U2'), $ref('A')]],
            'U2' => ['anyOf' => [$ref('U3'), $ref('B')]],
            'U3' => ['anyOf' => [$ref('C'), $ref('D')]],
            'S' => ['allOf' => [$ref('U1'), $object(['e' => $object(['ee' => ['type' => 'string']])])]],
            'T' => ['allOf' => [$ref('S'), $object(['t' => ['type' => 'string']])]],
            'ZS' => ['allOf' => [$ref('S'), $object(['z' => ['type' => 'string']])]],
            'Holder' => $object([
                'p' => ['allOf' => [['anyOf' => [$ref('A'), $ref('U3')]], $object(['e' => $object(['ee' => ['type' => 'string']])])]],
                'list' => ['type' => 'array', 'items' => ['allOf' => [['anyOf' => [$ref('A'), $object(['x' => ['type' => 'string']]) + ['title' => 'extra item']]], $object(['e' => $object(['ee' => ['type' => 'string']])])]]],
            ]),
            'Batch' => ['type' => 'array', 'items' => ['allOf' => [['anyOf' => [$ref('A'), $ref('B')]], $object(['e' => $object(['ee' => ['type' => 'string']])])]]],
            'Uses' => $object(['batch' => $ref('Batch')]),
            'Inner' => ['allOf' => [
                ['anyOf' => [['title' => 'X', 'type' => 'object', 'properties' => ['q' => $object(['qq' => ['type' => 'string']])], 'allOf' => [['anyOf' => [$ref('C'), $ref('D')]]]], $ref('B')]],
                $object(['i' => ['type' => 'string']]),
            ]],
        ]);
        $classes = ModelFixture::classes($output);

        self::assertSame([], ModelFixture::messages($output));
        foreach (['TSA', 'TSU2B', 'TSU2U3C', 'TSU2U3D'] as $class) {
            self::assertSame(['t: string|null'], $classes['App\\Dto\\' . $class]);
        }

        self::assertSame(['z: string|null'], $classes['App\\Dto\\ZSSA']);
        foreach (['SA', 'SU2B', 'SU2U3C', 'SU2U3D'] as $class) {
            self::assertSame(['e: App\\Dto\\SE|null'], $classes['App\\Dto\\' . $class]);
        }

        self::assertSame(['p: App\\Dto\\HolderPA|App\\Dto\\HolderPU3C|App\\Dto\\HolderPU3D|null', 'list: list<App\\Dto\\HolderListItemA|App\\Dto\\HolderListItemExtraItem>|null'], $classes['App\\Dto\\Holder']);
        self::assertSame(['e: App\\Dto\\HolderListItemE|null'], $classes['App\\Dto\\HolderListItemA']);
        self::assertSame(['x: string|null', 'e: App\\Dto\\HolderListItemE|null'], $classes['App\\Dto\\HolderListItemExtraItem']);

        foreach (['BatchItemA', 'BatchItemB'] as $class) {
            self::assertSame(['e: App\\Dto\\BatchItemE|null'], $classes['App\\Dto\\' . $class]);
        }

        foreach (['HolderPA', 'HolderPU3C', 'HolderPU3D'] as $class) {
            self::assertSame(['e: App\\Dto\\HolderPE|null'], $classes['App\\Dto\\' . $class]);
        }

        self::assertSame(['c: string|null', 'q: App\\Dto\\InnerXQ|null', 'i: string|null'], $classes['App\\Dto\\InnerXC']);
        self::assertSame(['d: string|null', 'q: App\\Dto\\InnerXQ|null', 'i: string|null'], $classes['App\\Dto\\InnerXD']);
    }

    /**
     * @dataProvider variantOrders
     *
     * @param list<array<array-key, mixed>> $variants
     * @param list<string> $classes the variant classes, the classes of what they share last
     */
    public function testNamesWhatVariantsShareAlikeInAnyOrder(array $variants, array $classes, bool $inline = false): void
    {
        $object = static fn (array $properties): array => ['type' => 'object', 'properties' => $properties];
        $common = $object(['e' => $object(['ee' => ['type' => 'string']])]);
        $shared = $classes[count($classes) - 1];
        foreach ([$variants, array_reverse($variants)] as $order) {
            $schema = ['allOf' => [['anyOf' => $order], $common]];
            $output = ModelFixture::build([
                'List' => $object(['l' => ['type' => 'string']]),
                'B' => $object(['b' => ['type' => 'string']]),
                'A' => $object(['a' => ['type' => 'string']]) + ['x-php-class-name' => 'Aye'],
            ] + ($inline ? ['H' => $object(['p' => $schema])] : ['S' => $schema]));
            $generated = ModelFixture::classes($output);

            self::assertSame([], ModelFixture::messages($output));
            foreach (array_slice($classes, 0, -1) as $class) {
                self::assertContains("e: App\\Dto\\{$shared}|null", $generated['App\\Dto\\' . $class]);
            }
        }
    }

    /**
     * @return iterable<string, array{0: list<array<array-key, mixed>>, 1: list<string>, 2?: bool}>
     */
    public static function variantOrders(): iterable
    {
        $titled = static fn (string $title, string $property): array => ['type' => 'object', 'title' => $title, 'properties' => [$property => ['type' => 'string']]];
        $ref = static fn (string $name): array => ['$ref' => '#/components/schemas/' . $name];

        yield 'a reserved word' => [[$ref('List'), $ref('B')], ['SList', 'SB', 'SE']];
        yield 'a class name of the variant' => [[$ref('A'), $ref('B')], ['SAye', 'SB', 'SE']];
        yield 'a leading digit' => [[$titled('2fa', 'x'), $ref('B')], ['HP2fa', 'HPB', 'HPE'], true];
        yield 'names without letters' => [[$titled('+', 'x'), $titled('-', 'y')], ['SOption1', 'SOption2', 'SE']];
        yield 'names alike as class names' => [[$titled('foo bar', 'x'), $titled('foo-bar', 'y')], ['SOption1', 'SOption2', 'SE']];
    }

    public function testStartsTheNamesOfVariantsWithTheClassNameOfTheirSchema(): void
    {
        $object = static fn (array $properties): array => ['type' => 'object', 'properties' => $properties];
        $output = ModelFixture::build([
            'A' => $object(['a' => ['type' => 'string']]),
            'B' => $object(['b' => ['type' => 'string']]),
            'S' => ['x-php-class-name' => 'My_Dto', 'allOf' => [['anyOf' => [['$ref' => '#/components/schemas/A'], ['$ref' => '#/components/schemas/B']]], $object(['e' => $object(['ee' => ['type' => 'string']])])]],
        ]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(['App\\Dto\\A', 'App\\Dto\\B', 'App\\Dto\\My_DtoA', 'App\\Dto\\My_DtoB', 'App\\Dto\\MyDtoE'], array_keys(ModelFixture::classes($output)));

        $reserved = ModelFixture::build([
            'S' => ['x-php-class-name' => 'Se', 'allOf' => [['anyOf' => [$object(['a' => ['type' => 'string']]) + ['title' => 'lf'], $object(['b' => ['type' => 'string']]) + ['title' => 'b']]], $object(['e' => ['type' => 'string']])]],
        ]);

        self::assertSame([], ModelFixture::messages($reserved));
        self::assertSame(['App\\Dto\\SeLf_', 'App\\Dto\\SeB'], array_keys(ModelFixture::classes($reserved)));
    }

    public function testNamesVariantsWithTheSameTitleByPosition(): void
    {
        $object = static fn (array $properties): array => ['type' => 'object', 'properties' => $properties];
        $output = ModelFixture::build([
            'S' => ['x-php-class-name' => 'Renamed', 'allOf' => [
                ['oneOf' => [$object(['p' => ['type' => 'string']]) + ['title' => 'T'], $object(['q' => ['type' => 'string']]) + ['title' => 'T'], $object(['r' => ['type' => 'string']]) + ['title' => 'u']]],
                $object(['x' => ['type' => 'string']]),
            ]],
        ]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(['App\\Dto\\RenamedOption1', 'App\\Dto\\RenamedOption2', 'App\\Dto\\RenamedU'], array_keys(ModelFixture::classes($output)));
    }

    public function testReportsAClassNameOfADistributedSchemaThatIsNoIdentifier(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(self::AT . 'S/x-php-class-name: "x-php-class-name" must be a PHP identifier that is not a reserved word.');

        $object = static fn (array $properties): array => ['type' => 'object', 'properties' => $properties];
        GraphFixture::load([
            'A' => $object(['a' => ['type' => 'string']]),
            'S' => ['x-php-class-name' => 'Sub\\Bad', 'allOf' => [['anyOf' => [['$ref' => '#/components/schemas/A'], $object(['q' => ['type' => 'string']])]], $object(['x' => ['type' => 'string']])]],
        ]);
    }

    public function testKeepsWhatAppliesToTheWholeValueOnTheUnion(): void
    {
        $object = static fn (array $properties): array => ['type' => 'object', 'properties' => $properties];
        $output = ModelFixture::views([
            'A' => $object(['a' => ['type' => 'string']]),
            'B' => $object(['b' => ['type' => 'integer']]),
            'E' => ['anyOf' => [['$ref' => '#/components/schemas/A'], ['$ref' => '#/components/schemas/B']]],
            'H' => $object([
                'id' => ['type' => 'string'],
                'alone' => ['allOf' => [['$ref' => '#/components/schemas/E']], 'readOnly' => true],
            ]),
        ]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(['id: string|null', 'alone: App\\Dto\\A|App\\Dto\\B|null'], ModelFixture::classes($output)['App\\Dto\\HRead']);
        self::assertSame(['id: string|null'], ModelFixture::classes($output)['App\\Dto\\HWrite']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function strategies(): iterable
    {
        yield AllOfStrategy::EXTENDS => [AllOfStrategy::EXTENDS];
        yield AllOfStrategy::MERGE => [AllOfStrategy::MERGE];
    }

    public function testLeavesAnAllOfOfAnotherUnionAsWritten(): void
    {
        $at = self::AT;
        $object = static fn (array $properties): array => ['type' => 'object', 'properties' => $properties];
        $union = ['anyOf' => [['$ref' => '#/components/schemas/A'], ['$ref' => '#/components/schemas/B']]];
        $error = static fn (string $schema, int $member = 0): string => "error {$at}{$schema}/allOf/{$member}: An allOf member of a class must be an object schema, not a oneOf or anyOf: no class adds properties to each variant.";
        $output = ModelFixture::build([
            'A' => $object(['a' => ['type' => 'string']]),
            'B' => $object(['b' => ['type' => 'integer']]),
            'Scalars' => ['allOf' => [['oneOf' => [['type' => 'string'], $object(['c' => ['type' => 'string']])]], $object(['x' => ['type' => 'string']])]],
            'Twice' => ['allOf' => [$union, $union, $object(['x' => ['type' => 'string']])]],
            'Required' => ['allOf' => [$union + ['required' => ['a']], $object(['x' => ['type' => 'string']])]],
            'Typed' => ['allOf' => [['type' => 'string'] + $union, $object(['x' => ['type' => 'string']])]],
            'Itself' => ['allOf' => [['anyOf' => [['$ref' => '#/components/schemas/A'], ['$ref' => '#/components/schemas/Itself']]], $object(['x' => ['type' => 'string']])]],
            'Back' => ['allOf' => [['anyOf' => [['$ref' => '#/components/schemas/Through'], ['$ref' => '#/components/schemas/B']]], $object(['x' => ['type' => 'string']])]],
            'Through' => ['allOf' => [['$ref' => '#/components/schemas/Back'], $object(['t' => ['type' => 'string']])]],
            'Nested' => ['allOf' => [['anyOf' => [['$ref' => '#/components/schemas/A'], ['anyOf' => [['$ref' => '#/components/schemas/Nested']]]]], $object(['x' => ['type' => 'string']])]],
            'Nullable' => ['allOf' => [['anyOf' => [['$ref' => '#/components/schemas/A'], ['type' => 'null']]], $object(['x' => ['type' => 'string']])]],
            'Mixed' => ['allOf' => [$union, ['oneOf' => [['type' => 'string'], ['type' => 'integer']]], $object(['x' => ['type' => 'string']])]],
            'Both' => ['allOf' => [['oneOf' => [['$ref' => '#/components/schemas/A']], 'anyOf' => [['$ref' => '#/components/schemas/B']]], $object(['x' => ['type' => 'string']])]],
            'Skipped' => ['x-php-skip' => true, 'allOf' => [$union, $object(['x' => ['type' => 'string']])]],
            'Holder' => $object(['skipped' => ['$ref' => '#/components/schemas/Skipped']]),
        ]);

        self::assertSame([$error('Scalars'), $error('Twice'), $error('Twice', 1), $error('Required'), $error('Typed'), $error('Itself'), $error('Back'), $error('Nested'), $error('Nullable'), $error('Mixed'), $error('Mixed', 1), $error('Both'), "warning {$at}Holder/properties/skipped: \$ref points to a schema excluded by \"x-php-skip\"."], ModelFixture::messages($output));
        self::assertSame(['skipped: mixed'], ModelFixture::classes($output)['App\\Dto\\Holder']);
    }

    public function testMeetsNoClassWithOneOfAView(): void
    {
        $at = self::AT;
        $viewed = ModelFixture::views([
            'Wide' => ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]],
            'Req' => ['allOf' => [
                ['type' => 'object', 'properties' => ['opts' => ['$ref' => '#/components/schemas/Wide']]],
                ['type' => 'object', 'properties' => ['opts' => ['$ref' => '#/components/schemas/Narrow']]],
            ]],
            'Narrow' => ['allOf' => [['$ref' => '#/components/schemas/Wide'], ['type' => 'object', 'properties' => ['b' => ['type' => 'string', 'readOnly' => true]]]]],
        ]);

        self::assertSame(["error {$at}Req/allOf/1/properties/opts: Property \"opts\" of App\\Dto\\ReqRead is App\\Dto\\NarrowRead|null here, but App\\Dto\\Wide|null in an earlier allOf member."], ModelFixture::messages($viewed));
    }

    public function testMeetsNoClassWhoseSchemaLeavesOutWhatItHolds(): void
    {
        $at = self::AT;
        $narrow = ['type' => 'object', 'properties' => ['a' => ['type' => 'string'], 'b' => ['type' => 'string']]];
        $joined = ModelFixture::build([
            'Narrow' => $narrow,
            'Req' => ['allOf' => [
                ['type' => 'object', 'properties' => ['opts' => ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]]]],
                ['type' => 'object', 'properties' => ['opts' => ['type' => 'object', 'properties' => ['c' => ['type' => 'string']]]]],
                ['type' => 'object', 'properties' => ['opts' => ['$ref' => '#/components/schemas/Narrow']]],
            ]],
        ]);
        $viewed = ModelFixture::views([
            'Wide' => ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]],
            'Narrow' => ['properties' => ['a' => ['type' => 'string'], 'b' => ['type' => 'string', 'readOnly' => true]]] + $narrow,
            'Req' => ['allOf' => [
                ['type' => 'object', 'properties' => ['opts' => ['$ref' => '#/components/schemas/Wide']]],
                ['type' => 'object', 'properties' => ['opts' => ['$ref' => '#/components/schemas/Narrow']]],
            ]],
        ]);

        self::assertSame(["error {$at}Req/allOf/2/properties/opts: Property \"opts\" of App\\Dto\\Req is App\\Dto\\Narrow|null here, but App\\Dto\\ReqOpts|null in an earlier allOf member."], ModelFixture::messages($joined));
        self::assertSame(["error {$at}Req/allOf/1/properties/opts: Property \"opts\" of App\\Dto\\ReqRead is App\\Dto\\NarrowRead|null here, but App\\Dto\\Wide|null in an earlier allOf member."], ModelFixture::messages($viewed));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, array<string, mixed>, string}>
     */
    public static function shapes(): iterable
    {
        $ttl = ['$ref' => '#/components/schemas/Ttl', 'description' => 'How long.'];
        $tags = ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'uuid']];
        $wide = ['type' => 'object', 'title' => 'Options', 'description' => 'Options.', 'required' => ['ttl'], 'properties' => ['ttl' => $ttl, 'tags' => $tags]];
        $narrow = ['type' => 'object', 'required' => ['ttl', 'more'], 'properties' => ['ttl' => ['$ref' => '#/components/schemas/Ttl', 'description' => 'Other words.'], 'tags' => $tags, 'more' => ['type' => 'boolean']]];

        yield 'more properties' => [$wide, $narrow, 'Narrow'];
        yield 'the same properties' => [$wide, ['required' => ['ttl']] + $narrow + ['additionalProperties' => false], 'Narrow'];
        yield 'an empty object' => [['type' => 'object'], $narrow, 'Narrow'];
        yield 'a narrower allOf' => [$wide, ['allOf' => [['type' => 'object', 'properties' => ['x' => ['type' => 'string']]]]] + $narrow, 'error'];
        yield 'a property it lacks' => [$wide, ['properties' => ['ttl' => $ttl]] + $narrow, 'error'];
        yield 'a property it does not require' => [$wide, ['required' => ['more']] + $narrow, 'error'];
        yield 'another property schema' => [$wide, ['properties' => ['ttl' => $ttl, 'tags' => ['type' => 'array', 'items' => ['type' => 'string']]]] + $narrow, 'error'];
        yield 'another default' => [$wide, ['properties' => ['ttl' => $ttl + ['default' => '30m'], 'tags' => $tags]] + $narrow, 'error'];
        yield 'an x- key on a property' => [$wide, ['properties' => ['ttl' => $ttl + ['x-php-name' => 'life'], 'tags' => $tags]] + $narrow, 'error'];
        yield 'a keyword on a property' => [$wide, ['properties' => ['ttl' => $ttl, 'tags' => $tags + ['minItems' => 1]]] + $narrow, 'error'];
        yield 'an inline object property' => [['properties' => ['o' => ['type' => 'object']]] + $wide, ['properties' => ['o' => ['type' => 'object'], 'ttl' => $ttl, 'tags' => $tags]] + $narrow, 'error'];
        yield 'no other properties' => [['additionalProperties' => false] + $wide, $narrow, 'error'];
        yield 'other properties of a type' => [['additionalProperties' => ['type' => 'string']] + $wide, $narrow, 'error'];
        yield 'an allOf' => [['allOf' => [['type' => 'object']]] + $wide, $narrow, 'error'];
        yield 'an x- key' => [['x-dto-mutable' => true] + $wide, $narrow, 'error'];
        yield 'a keyword' => [['minProperties' => 1] + $wide, $narrow, 'error'];
        yield 'null' => [['type' => ['object', 'null']] + $wide, $narrow, 'Narrow'];
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
            'class extending itself' => [
                ['A' => ['type' => 'object', 'properties' => ['a' => []], 'allOf' => [['$ref' => '#/components/schemas/A']]]],
                ["error {$at}A/allOf/0: Class App\\Dto\\A extends itself."],
            ],
            'class merging itself' => [
                ['A' => ['type' => 'object', 'properties' => ['a' => []], 'allOf' => [['$ref' => '#/components/schemas/A']], 'x-php-all-of' => 'merge']],
                ["error {$at}A/allOf/0: The allOf chain loops back to a schema it is already merging."],
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
                ["error {$at}Cat/allOf/0: An allOf member of a class must be an object schema, not a oneOf or anyOf: no class adds properties to each variant."],
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
                    "warning {$at}Cat: Required property \"id\" belongs to the parent App\\Dto\\Animal, where extending cannot make it required; use \"x-php-all-of: merge\" to require it.",
                    "warning {$at}Cat: Required property \"name\" belongs to the parent App\\Dto\\Pet, where extending cannot make it required; use \"x-php-all-of: merge\" to require it.",
                    "warning {$at}Cat: Required property \"tag\" belongs to the parent App\\Dto\\Animal, where extending cannot make it required; use \"x-php-all-of: merge\" to require it.",
                ],
            ],
            'discriminated parent in an inheritance loop' => [
                [
                    'A' => ['allOf' => [['$ref' => '#/components/schemas/B'], ['properties' => ['kind' => ['type' => 'string']]]], 'discriminator' => ['propertyName' => 'kind']],
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

    public function testMakesClassesOfTheInlineVariantsOfANamedUnion(): void
    {
        $at = self::AT;
        $output = ModelFixture::build([
            'Event' => ['oneOf' => [
                ['type' => 'object', 'title' => 'Created', 'properties' => ['type' => ['type' => 'string', 'const' => 'created'], 'id' => ['type' => 'string']]],
                ['type' => 'object', 'properties' => ['type' => ['type' => 'string', 'enum' => ['deleted']], 'at' => ['type' => 'string']]],
                ['$ref' => '#/components/schemas/Moved'],
                ['type' => 'object', 'properties' => ['type' => ['type' => 'string', 'enum' => ['a', 'b']]]],
                ['allOf' => [['properties' => ['type' => ['type' => 'string', 'const' => 'merged']]], ['properties' => ['m' => ['type' => 'string']]]]],
            ], 'discriminator' => ['propertyName' => 'type']],
            'Moved' => ['type' => 'object', 'properties' => ['type' => ['type' => 'string']]],
        ]);

        self::assertSame(
            ["error {$at}Event/oneOf/3: An inline variant of a discriminated union needs one value of \"type\", in \"const\" or a one-value \"enum\", as the mapping cannot name it."],
            ModelFixture::messages($output),
        );
        $hierarchy = ModelFixture::hierarchy($output);
        self::assertSame('abstract by type {created: App\\Dto\\Created, deleted: App\\Dto\\EventOption2, Moved: App\\Dto\\Moved, merged: App\\Dto\\EventOption5}', $hierarchy['App\\Dto\\Event']);
        self::assertSame('final extends App\\Dto\\Event', $hierarchy['App\\Dto\\Created']);
        self::assertSame('final extends App\\Dto\\Event', $hierarchy['App\\Dto\\EventOption2']);
    }

    public function testLooksForTheValueOfAnInlineVariantThroughACycle(): void
    {
        $at = self::AT;
        $output = ModelFixture::build([
            'Event' => ['oneOf' => [
                ['allOf' => [['$ref' => '#/components/schemas/Loop1'], ['properties' => ['x' => ['type' => 'string']]]]],
                ['type' => 'string'],
            ], 'discriminator' => ['propertyName' => 'type']],
            'Loop1' => ['allOf' => [['$ref' => '#/components/schemas/Loop2']]],
            'Loop2' => ['allOf' => [['$ref' => '#/components/schemas/Loop1']]],
        ], [], ['*'], AllOfStrategy::MERGE);

        self::assertContains("error {$at}Event/oneOf/0: An inline variant of a discriminated union needs one value of \"type\", in \"const\" or a one-value \"enum\", as the mapping cannot name it.", ModelFixture::messages($output));
        self::assertArrayNotHasKey('App\\Dto\\EventOption2', ModelFixture::classes($output));
    }

    public function testKeepsAClassBaseForAVariantThatDescendsFromIt(): void
    {
        $output = ModelFixture::build([
            'Pet' => ['oneOf' => [['$ref' => '#/components/schemas/Cat'], ['$ref' => '#/components/schemas/Dog']], 'discriminator' => ['propertyName' => 'kind']],
            'Cat' => ['allOf' => [['$ref' => '#/components/schemas/Pet'], ['type' => 'object', 'properties' => ['kind' => ['type' => 'string']]]]],
            'Dog' => ['type' => 'object', 'properties' => ['kind' => ['type' => 'string']]],
        ]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame('abstract by kind {Cat: App\\Dto\\Cat, Dog: App\\Dto\\Dog}', ModelFixture::hierarchy($output)['App\\Dto\\Pet']);
    }

    public function testMakesAnInterfaceOfAUnionWhoseVariantsExtendOtherClasses(): void
    {
        $ref = static fn (string $name): array => ['$ref' => '#/components/schemas/' . $name];
        $variant = static fn (string $type): array => ['type' => 'object', 'properties' => ['type' => ['type' => 'string', 'const' => $type]]];
        $schemas = [
            'OutputItem' => ['oneOf' => [$ref('Call'), $ref('Msg')], 'discriminator' => ['propertyName' => 'type']],
            'ItemField' => ['oneOf' => [$ref('Call'), $ref('Msg'), $ref('Other')], 'discriminator' => ['propertyName' => 'type']],
            'ItemResource' => ['oneOf' => [$ref('CallResource'), $ref('Msg')], 'discriminator' => ['propertyName' => 'type']],
            'Call' => $variant('Call'),
            'Msg' => $variant('Msg'),
            'Other' => $variant('Other'),
            'CallResource' => ['allOf' => [$ref('Call'), ['type' => 'object', 'properties' => ['id' => ['type' => 'string']]]]],
        ];
        $output = ModelFixture::build($schemas);
        $reversed = ModelFixture::hierarchy(ModelFixture::build(array_reverse($schemas)));

        // CallResource inherits the one type of Call, which selects it in ItemResource rather than its schema name.
        self::assertSame([], ModelFixture::messages($output));
        // Of the unions that could each be the class, the first by name is; the order of the schemas plays no part.
        self::assertSame([
            'App\\Dto\\OutputItem' => 'interface by type {Call: App\\Dto\\Call, Msg: App\\Dto\\Msg}',
            'App\\Dto\\ItemField' => 'abstract by type {Call: App\\Dto\\Call, Msg: App\\Dto\\Msg, Other: App\\Dto\\Other}',
            'App\\Dto\\ItemResource' => 'interface by type {Call: App\\Dto\\CallResource, Msg: App\\Dto\\Msg}',
            'App\\Dto\\Call' => 'open extends App\\Dto\\ItemField implements App\\Dto\\OutputItem',
            'App\\Dto\\Msg' => 'final extends App\\Dto\\ItemField implements App\\Dto\\ItemResource, App\\Dto\\OutputItem',
            'App\\Dto\\Other' => 'final extends App\\Dto\\ItemField',
            'App\\Dto\\CallResource' => 'final extends App\\Dto\\Call implements App\\Dto\\ItemResource',
        ], ModelFixture::hierarchy($output));
        ksort($reversed);
        $hierarchy = ModelFixture::hierarchy($output);
        ksort($hierarchy);
        self::assertSame($hierarchy, $reversed);
    }

    public function testKeepsAUnionWithPropertiesTheClassOfAVariantItShares(): void
    {
        $catRef = ['$ref' => '#/components/schemas/Cat'];
        $discriminator = ['propertyName' => 'kind'];
        $schemas = [
            'Animal' => ['oneOf' => [$catRef], 'discriminator' => $discriminator],
            'Pet' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']], 'oneOf' => [$catRef], 'discriminator' => $discriminator],
            'Cat' => ['type' => 'object', 'properties' => ['kind' => ['type' => 'string']]],
        ];

        foreach ([$schemas, array_reverse($schemas)] as $order) {
            $output = ModelFixture::build($order);

            self::assertSame([], ModelFixture::messages($output));
            self::assertSame('interface by kind {Cat: App\\Dto\\Cat}', ModelFixture::hierarchy($output)['App\\Dto\\Animal']);
            self::assertSame('final extends App\\Dto\\Pet implements App\\Dto\\Animal', ModelFixture::hierarchy($output)['App\\Dto\\Cat']);
        }
    }

    public function testKeepsAUnionThatAClassExtendsAClass(): void
    {
        $ref = static fn (string $name): array => ['$ref' => '#/components/schemas/' . $name];
        $output = ModelFixture::build([
            'Pet' => ['oneOf' => [$ref('Cat'), $ref('Dog')], 'discriminator' => ['propertyName' => 'kind']],
            'Feline' => ['type' => 'object', 'properties' => ['claws' => ['type' => 'integer']]],
            'Cat' => ['allOf' => [$ref('Feline'), ['type' => 'object', 'properties' => ['kind' => ['type' => 'string']]]]],
            'Dog' => ['type' => 'object', 'properties' => ['kind' => ['type' => 'string']]],
            'Puppy' => ['allOf' => [$ref('Pet'), ['type' => 'object', 'properties' => ['age' => ['type' => 'integer']]]]],
        ]);

        self::assertContains('error ' . self::AT . 'Cat: App\\Dto\\Cat already extends App\\Dto\\Feline, so it cannot also be a variant of App\\Dto\\Pet.', ModelFixture::messages($output));
        self::assertStringStartsWith('abstract', ModelFixture::hierarchy($output)['App\\Dto\\Pet']);
    }

    /**
     * @return iterable<string, array{array<string, array<string, mixed>>}>
     */
    public static function nestedUnions(): iterable
    {
        $ref = static fn (string $name): array => ['$ref' => '#/components/schemas/' . $name];
        $kind = ['propertyName' => 'kind'];
        $variant = ['type' => 'object', 'properties' => ['kind' => ['type' => 'string']]];
        $schemas = [
            'Inner' => ['oneOf' => [$ref('Cat'), $ref('Fish')], 'discriminator' => $kind],
            'Outer' => ['oneOf' => [$ref('Inner'), $ref('Dog')], 'discriminator' => $kind],
            'Animal' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']]],
            'Cat' => $variant,
            'Dog' => $variant,
            'Fish' => ['allOf' => [$ref('Animal'), $variant]],
        ];

        yield 'inner first' => [$schemas];
        yield 'outer first' => [array_reverse($schemas)];
    }

    /**
     * @dataProvider nestedUnions
     *
     * @param array<string, array<string, mixed>> $schemas
     */
    public function testMakesAnInterfaceOfAUnionAnInterfaceVariantOfWhichExtendsIt(array $schemas): void
    {
        $output = ModelFixture::build($schemas);

        self::assertSame([], ModelFixture::messages($output));
        $hierarchy = ModelFixture::hierarchy($output);
        self::assertSame('interface implements App\\Dto\\Outer by kind {Cat: App\\Dto\\Cat, Fish: App\\Dto\\Fish}', $hierarchy['App\\Dto\\Inner']);
        self::assertSame('interface by kind {Inner: App\\Dto\\Inner, Dog: App\\Dto\\Dog}', $hierarchy['App\\Dto\\Outer']);
        self::assertSame('final implements App\\Dto\\Inner', $hierarchy['App\\Dto\\Cat']);
        self::assertSame('final implements App\\Dto\\Outer', $hierarchy['App\\Dto\\Dog']);
        self::assertSame(['App\\Dto\\Cat' => ["kind: 'Cat'"], 'App\\Dto\\Dog' => ["kind: 'Dog'"], 'App\\Dto\\Fish' => ["kind: 'Fish'"]], $this->sorted(ModelFixture::selections($output)));
    }

    /**
     * @param array<string, list<string>> $selections
     *
     * @return array<string, list<string>>
     */
    private function sorted(array $selections): array
    {
        ksort($selections);

        return $selections;
    }

    public function testSelectsTheVariantsOfAnInterfaceOrAbstractClassByTheValueThatNamesIt(): void
    {
        $ref = static fn (string $name): array => ['$ref' => '#/components/schemas/' . $name];
        $both = ['type' => 'object', 'properties' => ['kind' => ['type' => 'string'], 'family' => ['type' => 'string']]];
        $output = ModelFixture::build([
            'Kingdom' => ['oneOf' => [$ref('Feline'), $ref('Fish')], 'discriminator' => ['propertyName' => 'family']],
            'Feline' => ['oneOf' => [$ref('Cat'), $ref('Lion')], 'discriminator' => ['propertyName' => 'kind']],
            'Water' => ['oneOf' => [$ref('Tank'), $ref('Shark')], 'discriminator' => ['propertyName' => 'family']],
            'Tank' => ['oneOf' => [$ref('Guppy'), $ref('Eel')], 'discriminator' => ['propertyName' => 'kind']],
            'Animal' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']]],
            'Cat' => $both,
            'Lion' => $both,
            'Guppy' => $both,
            'Eel' => ['allOf' => [$ref('Animal'), $both]],
            'Shark' => ['allOf' => [$ref('Animal'), $both]],
            'Fish' => ['allOf' => [$ref('Animal'), $both]],
        ]);

        self::assertSame([], ModelFixture::messages($output));
        $hierarchy = ModelFixture::hierarchy($output);
        self::assertSame('abstract implements App\\Dto\\Kingdom by kind {Cat: App\\Dto\\Cat, Lion: App\\Dto\\Lion}', $hierarchy['App\\Dto\\Feline']);
        self::assertSame('interface implements App\\Dto\\Water by kind {Guppy: App\\Dto\\Guppy, Eel: App\\Dto\\Eel}', $hierarchy['App\\Dto\\Tank']);
        $selections = ModelFixture::selections($output);
        self::assertSame(["family: 'Feline'", "kind: 'Cat'"], $selections['App\\Dto\\Cat']);
        self::assertSame(["family: 'Tank'", "kind: 'Guppy'"], $selections['App\\Dto\\Guppy']);
        self::assertSame(["family: 'Fish'"], $selections['App\\Dto\\Fish']);
    }

    public function testAcceptsTheValueOfEveryBaseThatMapsAClass(): void
    {
        $ref = static fn (string $name): array => ['$ref' => '#/components/schemas/' . $name];
        $kind = static fn (array $mapping): array => ['propertyName' => 'kind', 'mapping' => $mapping];
        $variant = ['type' => 'object', 'properties' => ['kind' => ['type' => 'string']]];
        $output = ModelFixture::build([
            'Pet' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']], 'oneOf' => [$ref('Cat')], 'discriminator' => $kind(['cat' => 'Cat'])],
            'Animal' => ['oneOf' => [$ref('Cat')], 'discriminator' => $kind(['feline' => 'Cat'])],
            'Item' => ['oneOf' => [$ref('Msg')], 'discriminator' => $kind(['msg' => 'Msg', 'm' => 'Msg'])],
            'Entry' => ['oneOf' => [$ref('Msg')], 'discriminator' => $kind(['msg' => 'Msg', 'message' => 'Msg'])],
            'Cat' => $variant,
            'Msg' => $variant,
        ]);

        self::assertSame([], ModelFixture::messages($output));
        $selections = ModelFixture::selections($output);
        self::assertSame(["kind: 'feline'|'cat'"], $selections['App\\Dto\\Cat']);
        self::assertSame(["kind: 'msg'|'m'|'message'"], $selections['App\\Dto\\Msg']);
    }

    public function testReportsUnionsWhoseInterfacesWouldExtendEachOther(): void
    {
        $ref = static fn (string $name): array => ['$ref' => '#/components/schemas/' . $name];
        $kind = ['propertyName' => 'kind'];
        $variant = ['allOf' => [$ref('Base'), ['type' => 'object', 'properties' => ['kind' => ['type' => 'string']]]]];
        $output = ModelFixture::build([
            'Loop1' => ['oneOf' => [$ref('Loop2'), $ref('X')], 'discriminator' => $kind],
            'Loop2' => ['oneOf' => [$ref('Loop1'), $ref('Y')], 'discriminator' => $kind],
            'Base' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']]],
            'X' => $variant,
            'Y' => $variant,
        ]);

        self::assertSame(['error ' . self::AT . 'Loop1: Interface App\\Dto\\Loop1 would extend itself through App\\Dto\\Loop2.'], ModelFixture::messages($output));
        self::assertSame('interface by kind {Loop2: App\\Dto\\Loop2, X: App\\Dto\\X}', ModelFixture::hierarchy($output)['App\\Dto\\Loop1']);
        self::assertSame('final extends App\\Dto\\Base implements App\\Dto\\Loop2', ModelFixture::hierarchy($output)['App\\Dto\\Y']);
    }

    public function testReportsAnInterfaceThatListsItself(): void
    {
        $ref = static fn (string $name): array => ['$ref' => '#/components/schemas/' . $name];
        $output = ModelFixture::build([
            'Pet' => ['oneOf' => [$ref('Pet'), $ref('Cat')], 'discriminator' => ['propertyName' => 'kind']],
            'Base' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']]],
            'Cat' => ['allOf' => [$ref('Base'), ['type' => 'object', 'properties' => ['kind' => ['type' => 'string']]]]],
        ]);

        self::assertSame(['error ' . self::AT . 'Pet: App\\Dto\\Pet lists itself among its variants.'], ModelFixture::messages($output));
        self::assertSame('final extends App\\Dto\\Base implements App\\Dto\\Pet', ModelFixture::hierarchy($output)['App\\Dto\\Cat']);
    }

    public function testReportsAVariantOfAnInterfaceWithoutTheDiscriminatorProperty(): void
    {
        $ref = static fn (string $name): array => ['$ref' => '#/components/schemas/' . $name];
        $output = ModelFixture::build([
            'Outer' => ['oneOf' => [$ref('Inner'), $ref('Dog')], 'discriminator' => ['propertyName' => 'kind']],
            'Inner' => ['oneOf' => [$ref('Cat'), $ref('Fish')], 'discriminator' => ['propertyName' => 'kind']],
            'Animal' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']]],
            'Cat' => ['type' => 'object', 'properties' => ['kind' => ['type' => 'string']]],
            'Dog' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
            'Fish' => ['allOf' => [$ref('Animal'), ['type' => 'object', 'properties' => ['kind' => ['type' => 'string']]]]],
        ]);

        self::assertSame(['warning ' . self::AT . 'Dog: Variant App\\Dto\\Dog has no property "kind", which the discriminator reads.'], ModelFixture::messages($output));
    }

    public function testReportsTheClassesOfAnInterfaceVariantWithoutTheProperty(): void
    {
        $ref = static fn (string $name): array => ['$ref' => '#/components/schemas/' . $name];
        $kindOnly = ['type' => 'object', 'properties' => ['kind' => ['type' => 'string']]];
        $output = ModelFixture::build([
            'Kingdom' => ['oneOf' => [$ref('Tank'), $ref('Shark')], 'discriminator' => ['propertyName' => 'family']],
            'Tank' => ['oneOf' => [$ref('Guppy'), $ref('Eel')], 'discriminator' => ['propertyName' => 'kind']],
            'Animal' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']]],
            'Guppy' => $kindOnly,
            'Eel' => ['allOf' => [$ref('Animal'), $kindOnly]],
            'Shark' => ['allOf' => [$ref('Animal'), ['type' => 'object', 'properties' => ['family' => ['type' => 'string']]]]],
        ]);

        self::assertSame([
            'warning ' . self::AT . 'Guppy: Variant App\\Dto\\Guppy has no property "family", which the discriminator reads.',
            'warning ' . self::AT . 'Eel: Variant App\\Dto\\Eel has no property "family", which the discriminator reads.',
        ], ModelFixture::messages($output));
    }

    public function testChecksNoInterfaceForTheDiscriminatorProperty(): void
    {
        $ref = static fn (string $name): array => ['$ref' => '#/components/schemas/' . $name];
        $kind = ['propertyName' => 'kind'];
        $variant = ['type' => 'object', 'properties' => ['kind' => ['type' => 'string']]];
        $output = ModelFixture::build([
            'Top' => ['oneOf' => [$ref('Mid'), $ref('Dog')], 'discriminator' => $kind],
            'Mid' => ['oneOf' => [$ref('Low'), $ref('Bird')], 'discriminator' => $kind],
            'Low' => ['oneOf' => [$ref('Cat'), $ref('Fish')], 'discriminator' => $kind],
            'Animal' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']]],
            'Cat' => $variant,
            'Dog' => $variant,
            'Bird' => $variant,
            'Fish' => ['allOf' => [$ref('Animal'), $variant]],
        ]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame('interface implements App\\Dto\\Mid by kind {Cat: App\\Dto\\Cat, Fish: App\\Dto\\Fish}', ModelFixture::hierarchy($output)['App\\Dto\\Low']);
    }

    public function testLeavesTheMutatorsOfWhatAnInterfaceDiscriminatesOut(): void
    {
        $ref = static fn (string $name): array => ['$ref' => '#/components/schemas/' . $name];
        $variant = ['type' => 'object', 'properties' => ['kind' => ['type' => 'string'], 'name' => ['type' => 'string']]];
        $output = ModelFixture::build([
            'Outer' => ['oneOf' => [$ref('Inner'), $ref('Dog')], 'discriminator' => ['propertyName' => 'kind']],
            'Inner' => ['oneOf' => [$ref('Cat'), $ref('Fish')], 'discriminator' => ['propertyName' => 'kind']],
            'Animal' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']]],
            'Cat' => $variant,
            'Dog' => $variant,
            'Fish' => ['allOf' => [$ref('Animal'), $variant]],
        ]);

        $discriminated = [];
        foreach ($output->classes() as $class) {
            $discriminated[$class->model()->name()->shortName()] = $class->model()->discriminatedProperties();
        }

        self::assertSame(['kind'], $discriminated['Cat']);
        self::assertSame(['kind'], $discriminated['Dog']);
        self::assertSame(['kind'], $discriminated['Fish']);
    }

    /**
     * @return iterable<string, array{array<string, array<string, mixed>>}>
     */
    public static function overlappingUnions(): iterable
    {
        $ref = static fn (string $name): array => ['$ref' => '#/components/schemas/' . $name];
        $kind = ['propertyName' => 'kind'];
        $variant = ['type' => 'object', 'properties' => ['kind' => ['type' => 'string']]];
        $schemas = [
            'Inner' => ['oneOf' => [$ref('A'), $ref('B')], 'discriminator' => $kind],
            'Outer' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']], 'oneOf' => [$ref('Inner'), $ref('A'), $ref('C')], 'discriminator' => $kind],
            'A' => $variant,
            'B' => $variant,
            'C' => $variant,
        ];

        yield 'inner first' => [$schemas];
        yield 'outer first' => [array_reverse($schemas)];
    }

    /**
     * @dataProvider overlappingUnions
     *
     * @param array<string, array<string, mixed>> $schemas
     */
    public function testAdoptsTheInnerUnionBeforeTheClassUnionThatListsItAndItsVariant(array $schemas): void
    {
        $output = ModelFixture::build($schemas);

        self::assertSame([], ModelFixture::messages($output));
        $hierarchy = ModelFixture::hierarchy($output);
        self::assertSame('abstract extends App\\Dto\\Outer by kind {A: App\\Dto\\A, B: App\\Dto\\B}', $hierarchy['App\\Dto\\Inner']);
        self::assertSame('final extends App\\Dto\\Inner', $hierarchy['App\\Dto\\A']);
        self::assertSame('final extends App\\Dto\\Outer', $hierarchy['App\\Dto\\C']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function namesOfAnotherUnion(): iterable
    {
        yield 'before the inner union' => ['Aj'];
        yield 'after the inner union' => ['Zj'];
    }

    /**
     * @dataProvider namesOfAnotherUnion
     */
    public function testLetsAClassUnionWaitingForItsInnerUnionTakeTheVariantItShares(string $other): void
    {
        $ref = static fn (string $name): array => ['$ref' => '#/components/schemas/' . $name];
        $kind = ['propertyName' => 'kind'];
        $variant = ['type' => 'object', 'properties' => ['kind' => ['type' => 'string']]];
        $output = ModelFixture::build([
            'C' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']], 'oneOf' => [$ref('I2'), $ref('X')], 'discriminator' => $kind],
            'I2' => ['oneOf' => [$ref('A'), $ref('B')], 'discriminator' => $kind],
            $other => ['oneOf' => [$ref('X'), $ref('Y')], 'discriminator' => $kind],
            'A' => $variant,
            'B' => $variant,
            'X' => $variant,
            'Y' => $variant,
        ]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame("final extends App\\Dto\\C implements App\\Dto\\{$other}", ModelFixture::hierarchy($output)['App\\Dto\\X']);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function namesOfASharingUnion(): iterable
    {
        yield 'before, in order' => ['A1', false];
        yield 'after, in order' => ['K1', false];
        yield 'before, reversed' => ['A1', true];
        yield 'after, reversed' => ['K1', true];
    }

    /**
     * Two class unions each waiting for an inner union, which must not wait for the other class union in turn.
     *
     * @dataProvider namesOfASharingUnion
     */
    public function testDecidesUnionsSharingAVariantWithAClassUnionAlikeWhateverTheirNames(string $sharing, bool $reversed): void
    {
        $ref = static fn (string $name): array => ['$ref' => '#/components/schemas/' . $name];
        $kind = ['propertyName' => 'kind'];
        $variant = ['type' => 'object', 'properties' => ['kind' => ['type' => 'string']]];
        $class = ['type' => 'object', 'properties' => ['id' => ['type' => 'string']], 'discriminator' => $kind];
        $schemas = [
            'C1' => $class + ['oneOf' => [$ref('Z1'), $ref('X1')]],
            'Z1' => ['oneOf' => [$ref('P1'), $ref('R1')], 'discriminator' => $kind],
            'C2' => $class + ['oneOf' => [$ref('Z2'), $ref('Q2')]],
            'Z2' => ['oneOf' => [$ref('P2'), $ref('R2')], 'discriminator' => $kind],
            $sharing => ['oneOf' => [$ref('X1'), $ref('Y1')], 'discriminator' => $kind],
        ];
        foreach (['X1', 'Y1', 'P1', 'R1', 'P2', 'R2', 'Q2'] as $name) {
            $schemas[$name] = $variant;
        }

        $output = ModelFixture::build($reversed ? array_reverse($schemas) : $schemas);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame("final extends App\\Dto\\C1 implements App\\Dto\\{$sharing}", ModelFixture::hierarchy($output)['App\\Dto\\X1']);
        self::assertSame('abstract extends App\\Dto\\C2 by kind {P2: App\\Dto\\P2, R2: App\\Dto\\R2}', ModelFixture::hierarchy($output)['App\\Dto\\Z2']);
    }

    public function testDecidesAUnionAClassUnionListsBeforeAnotherThatSharesItsVariant(): void
    {
        $ref = static fn (string $name): array => ['$ref' => '#/components/schemas/' . $name];
        $kind = ['propertyName' => 'kind'];
        $variant = ['type' => 'object', 'properties' => ['kind' => ['type' => 'string']]];
        $output = ModelFixture::build([
            'C1' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']], 'oneOf' => [$ref('Z1'), $ref('X1')], 'discriminator' => $kind],
            'Z1' => ['oneOf' => [$ref('X1'), $ref('W1')], 'discriminator' => $kind],
            'A1' => ['oneOf' => [$ref('X1'), $ref('Y1')], 'discriminator' => $kind],
            'X1' => $variant,
            'W1' => $variant,
            'Y1' => $variant,
        ]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame('final extends App\\Dto\\Z1 implements App\\Dto\\A1', ModelFixture::hierarchy($output)['App\\Dto\\X1']);
    }

    public function testDecidesAUnionAClassUnionListsThroughAnotherBeforeOneThatSharesItsVariant(): void
    {
        $ref = static fn (string $name): array => ['$ref' => '#/components/schemas/' . $name];
        $kind = ['propertyName' => 'kind'];
        $variant = ['type' => 'object', 'properties' => ['kind' => ['type' => 'string']]];
        $output = ModelFixture::build([
            'C1' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']], 'oneOf' => [$ref('M1'), $ref('X1')], 'discriminator' => $kind],
            'M1' => ['oneOf' => [$ref('Z1'), $ref('V1')], 'discriminator' => $kind],
            'Z1' => ['oneOf' => [$ref('X1'), $ref('W1')], 'discriminator' => $kind],
            'A1' => ['oneOf' => [$ref('X1'), $ref('Y1')], 'discriminator' => $kind],
            'X1' => $variant,
            'V1' => $variant,
            'W1' => $variant,
            'Y1' => $variant,
        ]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame('final extends App\\Dto\\Z1 implements App\\Dto\\A1', ModelFixture::hierarchy($output)['App\\Dto\\X1']);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function namesOfAUnionSharingWithAnInnerOne(): iterable
    {
        yield 'before, in order' => ['A1', false];
        yield 'after, in order' => ['Z9', false];
        yield 'before, reversed' => ['A1', true];
        yield 'after, reversed' => ['Z9', true];
    }

    /**
     * @dataProvider namesOfAUnionSharingWithAnInnerOne
     */
    public function testLetsAClassUnionTakeAVariantItReachesThroughAnInnerUnion(string $sharing, bool $reversed): void
    {
        $ref = static fn (string $name): array => ['$ref' => '#/components/schemas/' . $name];
        $kind = ['propertyName' => 'kind'];
        $variant = ['type' => 'object', 'properties' => ['kind' => ['type' => 'string']]];
        $schemas = [
            'C1' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']], 'oneOf' => [$ref('M1'), $ref('Q1')], 'discriminator' => $kind],
            'M1' => ['oneOf' => [$ref('X1'), $ref('W1')], 'discriminator' => $kind],
            $sharing => ['oneOf' => [$ref('X1'), $ref('Y1')], 'discriminator' => $kind],
            'Q1' => $variant,
            'X1' => $variant,
            'W1' => $variant,
            'Y1' => $variant,
        ];

        $output = ModelFixture::build($reversed ? array_reverse($schemas) : $schemas);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame("final extends App\\Dto\\M1 implements App\\Dto\\{$sharing}", ModelFixture::hierarchy($output)['App\\Dto\\X1']);
        self::assertSame('abstract extends App\\Dto\\C1 by kind {X1: App\\Dto\\X1, W1: App\\Dto\\W1}', ModelFixture::hierarchy($output)['App\\Dto\\M1']);
    }

    public function testLetsAClassUnionTakeAVariantBeforeAUnionAnotherClassListsSharesIt(): void
    {
        $ref = static fn (string $name): array => ['$ref' => '#/components/schemas/' . $name];
        $kind = ['propertyName' => 'kind'];
        $variant = ['type' => 'object', 'properties' => ['kind' => ['type' => 'string']]];
        $class = static fn (array $variants): array => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']], 'oneOf' => $variants, 'discriminator' => $kind];
        $output = ModelFixture::build([
            'C1' => $class([$ref('L'), $ref('Q')]),
            'L' => ['oneOf' => [$ref('X'), $ref('W')], 'discriminator' => $kind],
            'C2' => $class([$ref('X'), $ref('Z')]),
            'Q' => $variant,
            'X' => $variant,
            'W' => $variant,
            'Z' => $variant,
        ]);

        // X cannot extend both C2 and L, which C1 adopts: C2 is a class from the start, so it keeps X.
        self::assertSame(['error ' . self::AT . 'L: App\\Dto\\L is an interface, as a variant of it extends another class, so it cannot also be a variant of App\\Dto\\C1.'], ModelFixture::messages($output));
        self::assertSame('final extends App\\Dto\\C2 implements App\\Dto\\L', ModelFixture::hierarchy($output)['App\\Dto\\X']);
    }

    public function testDecidesUnionsThatOnlyOtherInterfacesListByName(): void
    {
        $ref = static fn (string $name): array => ['$ref' => '#/components/schemas/' . $name];
        $kind = ['propertyName' => 'kind'];
        $variant = ['type' => 'object', 'properties' => ['kind' => ['type' => 'string']]];
        $output = ModelFixture::build([
            'C1' => ['oneOf' => [$ref('B1'), $ref('Q')], 'discriminator' => $kind],
            'B1' => ['oneOf' => [$ref('X'), $ref('W')], 'discriminator' => $kind],
            'A2' => ['oneOf' => [$ref('X'), $ref('Y')], 'discriminator' => $kind],
            'Q' => $variant,
            'X' => $variant,
            'W' => $variant,
            'Y' => $variant,
        ]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame('final extends App\\Dto\\A2 implements App\\Dto\\B1', ModelFixture::hierarchy($output)['App\\Dto\\X']);
    }

    public function testLetsAClassUnionTakeTheUnionsItListsThroughAnother(): void
    {
        $ref = static fn (string $name): array => ['$ref' => '#/components/schemas/' . $name];
        $kind = ['propertyName' => 'kind'];
        $variant = ['type' => 'object', 'properties' => ['kind' => ['type' => 'string']]];
        $output = ModelFixture::build([
            'Bag' => ['oneOf' => [$ref('Inner'), $ref('Dog')], 'discriminator' => $kind],
            'Inner' => ['oneOf' => [$ref('Cat'), $ref('Fish')], 'discriminator' => $kind],
            'Cls' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']], 'oneOf' => [$ref('Mid'), $ref('X')], 'discriminator' => $kind],
            'Mid' => ['oneOf' => [$ref('Low'), $ref('Y')], 'discriminator' => $kind],
            'Low' => ['oneOf' => [$ref('A'), $ref('B')], 'discriminator' => $kind],
            'Animal' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']]],
            'Fish' => ['allOf' => [$ref('Animal'), $variant]],
            'Cat' => $variant,
            'Dog' => $variant,
            'X' => $variant,
            'Y' => $variant,
            'A' => $variant,
            'B' => $variant,
        ]);

        self::assertSame([], ModelFixture::messages($output));
        $hierarchy = ModelFixture::hierarchy($output);
        self::assertSame('interface implements App\\Dto\\Bag by kind {Cat: App\\Dto\\Cat, Fish: App\\Dto\\Fish}', $hierarchy['App\\Dto\\Inner']);
        self::assertSame('final extends App\\Dto\\Low', $hierarchy['App\\Dto\\A']);
        self::assertSame('abstract extends App\\Dto\\Cls by kind {Low: App\\Dto\\Low, Y: App\\Dto\\Y}', $hierarchy['App\\Dto\\Mid']);
    }

    public function testDecidesManyNestedUnionsAlikeInAnyOrder(): void
    {
        $ref = static fn (string $name): array => ['$ref' => '#/components/schemas/' . $name];
        $kind = ['propertyName' => 'kind'];
        $schemas = ['Animal' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']]]];
        // More unions than PHP 7.4 sorts stably (16), each an interface only when its inner one is decided first.
        foreach (range(1, 12) as $n) {
            $schemas["Group{$n}"] = ['oneOf' => [$ref("Inner{$n}"), $ref("Dog{$n}")], 'discriminator' => $kind];
            $schemas["Inner{$n}"] = ['oneOf' => [$ref("Cat{$n}"), $ref("Fish{$n}")], 'discriminator' => $kind];
            $schemas["Dog{$n}"] = ['type' => 'object', 'properties' => ['kind' => ['type' => 'string']]];
            $schemas["Cat{$n}"] = ['type' => 'object', 'properties' => ['kind' => ['type' => 'string']]];
            $schemas["Fish{$n}"] = ['allOf' => [$ref('Animal'), ['type' => 'object', 'properties' => ['kind' => ['type' => 'string']]]]];
        }

        foreach ([$schemas, array_reverse($schemas)] as $order) {
            $output = ModelFixture::build($order);

            self::assertSame([], ModelFixture::messages($output));
            foreach (range(1, 12) as $n) {
                self::assertSame("interface implements App\\Dto\\Group{$n} by kind {Cat{$n}: App\\Dto\\Cat{$n}, Fish{$n}: App\\Dto\\Fish{$n}}", ModelFixture::hierarchy($output)["App\\Dto\\Inner{$n}"]);
            }
        }
    }

    public function testReportsAnInterfaceAsTheVariantOfAUnionThatStaysAClass(): void
    {
        $ref = static fn (string $name): array => ['$ref' => '#/components/schemas/' . $name];
        $kind = ['propertyName' => 'kind'];
        $variant = ['type' => 'object', 'properties' => ['kind' => ['type' => 'string']]];
        $output = ModelFixture::build([
            'Top' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']], 'oneOf' => [$ref('Outer')], 'discriminator' => $kind],
            'Outer' => ['oneOf' => [$ref('Inner'), $ref('Dog')], 'discriminator' => $kind],
            'Inner' => ['oneOf' => [$ref('Cat'), $ref('Fish')], 'discriminator' => $kind],
            'Animal' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']]],
            'Cat' => $variant,
            'Dog' => $variant,
            'Fish' => ['allOf' => [$ref('Animal'), $variant]],
        ]);

        self::assertContains('error ' . self::AT . 'Outer: App\\Dto\\Outer is an interface, as a variant of it extends another class, so it cannot also be a variant of App\\Dto\\Top.', ModelFixture::messages($output));
    }

    public function testReportsAUnionWithPropertiesWhoseVariantExtendsAnotherClass(): void
    {
        $at = self::AT;
        $output = ModelFixture::build([
            'Base' => ['type' => 'object', 'properties' => ['x' => ['type' => 'string']]],
            'Pet' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']], 'oneOf' => [['$ref' => '#/components/schemas/Cat']], 'discriminator' => ['propertyName' => 'type']],
            'Cat' => ['allOf' => [['$ref' => '#/components/schemas/Base'], ['type' => 'object', 'properties' => ['type' => ['type' => 'string']]]]],
        ]);

        self::assertContains("error {$at}Cat: App\\Dto\\Cat already extends App\\Dto\\Base, so it cannot also be a variant of App\\Dto\\Pet.", ModelFixture::messages($output));
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

    public function testMakesAnInterfaceOfAUnionAVariantOfWhichHasAnotherParent(): void
    {
        $output = ModelFixture::build([
            'Pet' => ['oneOf' => [['$ref' => '#/components/schemas/Cat'], ['$ref' => '#/components/schemas/Dog']], 'discriminator' => ['propertyName' => 'kind']],
            'Animal' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']]],
            'Cat' => ['allOf' => [['$ref' => '#/components/schemas/Animal'], ['properties' => ['kind' => ['type' => 'string']]]]],
            'Dog' => ['type' => 'object', 'properties' => ['kind' => ['type' => 'string']]],
        ]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame([], ModelFixture::classes($output)['App\Dto\Pet']);
        self::assertSame(['kind: string|null'], ModelFixture::classes($output)['App\Dto\Dog']);
        self::assertSame('final implements App\Dto\Pet', ModelFixture::hierarchy($output)['App\Dto\Dog']);
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
        self::assertContains("error {$at}Pet/oneOf/0: An inline variant of a discriminated union needs one value of \"kind\", in \"const\" or a one-value \"enum\", as the mapping cannot name it.", $messages);
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
            'inline variant without a value' => [
                ['Pet' => ['oneOf' => [$catRef, ['type' => 'object', 'properties' => ['a' => []]]], 'discriminator' => $discriminator], 'Cat' => $cat],
                ["error {$at}Pet/oneOf/1: An inline variant of a discriminated union needs one value of \"kind\", in \"const\" or a one-value \"enum\", as the mapping cannot name it."],
            ],
            'inline variant that is no object' => [
                ['Pet' => ['oneOf' => [$catRef, ['type' => 'string']], 'discriminator' => $discriminator], 'Cat' => $cat],
                ["error {$at}Pet/oneOf/1: A variant of a discriminated union must be a \$ref to an object schema or an inline object."],
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
                    'Pet' => ['type' => 'object', 'properties' => ['name' => []], 'oneOf' => [$catRef], 'discriminator' => $discriminator],
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
                    "error {$at}Cat: Class App\\Dto\\Cat extends itself through App\\Dto\\Dog.",
                    "warning {$at}Cat: Variant App\\Dto\\Cat has no property \"kind\", which the discriminator reads.",
                    "warning {$at}Dog: Variant App\\Dto\\Dog has no property \"kind\", which the discriminator reads.",
                ],
            ],
            'variant that is the base, itself a variant' => [
                [
                    'Zoo' => ['oneOf' => [['$ref' => '#/components/schemas/Pet']], 'discriminator' => $discriminator],
                    'Pet' => ['oneOf' => [$catRef, ['$ref' => '#/components/schemas/Pet']], 'discriminator' => $discriminator],
                    'Cat' => $cat,
                ],
                [
                    "error {$at}Pet: App\\Dto\\Pet lists itself among its variants.",
                    "warning {$at}Pet: Variant App\\Dto\\Pet has no property \"kind\", which the discriminator reads.",
                ],
            ],
            'variant of two unions with properties' => [
                [
                    'Pet' => ['type' => 'object', 'properties' => ['name' => []], 'oneOf' => [$catRef], 'discriminator' => $discriminator],
                    'Animal' => ['type' => 'object', 'properties' => ['name' => []], 'oneOf' => [$catRef], 'discriminator' => $discriminator],
                    'Cat' => $cat,
                ],
                ["error {$at}Cat: App\\Dto\\Cat already extends App\\Dto\\Animal, so it cannot also be a variant of App\\Dto\\Pet."],
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

    public function testBuildsAClassThatExtendsItselfWithoutThatMember(): void
    {
        $self = ['$ref' => '#/components/schemas/A'];
        $output = ModelFixture::build([
            'A' => ['type' => 'object', 'properties' => ['a' => ['type' => 'string']], 'allOf' => [$self, ['properties' => ['b' => ['type' => 'integer']]]]],
            'W' => ['allOf' => [$self]],
        ]);

        self::assertSame(['error /project/api/openapi.yaml#/components/schemas/A/allOf/0: Class App\\Dto\\A extends itself.'], ModelFixture::messages($output));
        self::assertSame(['b: int|null', 'a: string|null'], ModelFixture::classes($output)['App\Dto\A']);
        self::assertSame('final', ModelFixture::hierarchy($output)['App\Dto\A']);
    }

    public function testFollowsAReferenceAliasAsAnAllOfMember(): void
    {
        $schemas = [
            'Pet' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
            'Alias' => ['$ref' => '#/components/schemas/Pet'],
            'Cat' => ['allOf' => [['$ref' => '#/components/schemas/Alias'], ['properties' => ['lives' => ['type' => 'integer']]]]],
        ];
        $extends = ModelFixture::build($schemas);
        $merge = ModelFixture::build($schemas, [], ['*'], AllOfStrategy::MERGE);

        self::assertSame([], ModelFixture::messages($extends));
        self::assertSame('final extends App\Dto\Pet', ModelFixture::hierarchy($extends)['App\Dto\Cat']);
        self::assertSame(['lives: int|null'], ModelFixture::classes($extends)['App\Dto\Cat']);
        self::assertSame([], ModelFixture::messages($merge));
        self::assertSame(['name: string|null', 'lives: int|null'], ModelFixture::classes($merge)['App\Dto\Cat']);
    }

    public function testReportsAnAliasThatLeadsBackToTheClass(): void
    {
        $schemas = [
            'A' => ['allOf' => [['$ref' => '#/components/schemas/Alias'], ['properties' => ['a' => ['type' => 'string']]]]],
            'Alias' => ['$ref' => '#/components/schemas/A'],
        ];

        self::assertSame(['error /project/api/openapi.yaml#/components/schemas/A/allOf/0: Class App\\Dto\\A extends itself.'], ModelFixture::messages(ModelFixture::build($schemas)));
        self::assertSame(['error /project/api/openapi.yaml#/components/schemas/A/allOf/0: The allOf chain loops back to a schema it is already merging.'], ModelFixture::messages(ModelFixture::build($schemas, [], ['*'], AllOfStrategy::MERGE)));
    }

    public function testKeepsTheRequirementsBesideAnAliasReference(): void
    {
        $output = ModelFixture::build([
            'Pet' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
            'Alias' => ['$ref' => '#/components/schemas/Pet', 'required' => ['name']],
            'Cat' => ['allOf' => [['$ref' => '#/components/schemas/Alias'], ['properties' => ['lives' => ['type' => 'integer']]]]],
        ], [], ['*'], AllOfStrategy::MERGE);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(['name: string', 'lives: int|null'], ModelFixture::classes($output)['App\Dto\Cat']);
    }

    public function testKeepsTheRequirementsBesideAReferenceMember(): void
    {
        $output = ModelFixture::build([
            'Pet' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
            'Cat' => ['allOf' => [['$ref' => '#/components/schemas/Pet', 'required' => ['name']], ['properties' => ['lives' => ['type' => 'integer']]]]],
        ], [], ['*'], AllOfStrategy::MERGE);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(['name: string', 'lives: int|null'], ModelFixture::classes($output)['App\Dto\Cat']);
    }

    public function testWarnsThatTheParentKeepsTheRequirementsBesideItsReference(): void
    {
        $warning = 'warning /project/api/openapi.yaml#/components/schemas/Cat: Required property "name" belongs to the parent App\\Dto\\Pet, where extending cannot make it required; use "x-php-all-of: merge" to require it.';
        $pet = ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]];
        $lives = ['properties' => ['lives' => ['type' => 'integer']]];

        $inline = ModelFixture::build(['Pet' => $pet, 'Cat' => ['allOf' => [['$ref' => '#/components/schemas/Pet', 'required' => ['name']], $lives]]]);
        $aliased = ModelFixture::build([
            'Pet' => $pet,
            'Alias' => ['$ref' => '#/components/schemas/Pet', 'required' => ['name']],
            'Cat' => ['allOf' => [['$ref' => '#/components/schemas/Alias'], $lives]],
        ]);

        $wrapped = ModelFixture::build([
            'Pet' => $pet,
            'Wrapper' => ['allOf' => [['$ref' => '#/components/schemas/Pet', 'required' => ['name']]]],
            'Cat' => ['allOf' => [['$ref' => '#/components/schemas/Wrapper'], $lives]],
        ]);

        self::assertSame([$warning], ModelFixture::messages($inline));
        self::assertSame([$warning], ModelFixture::messages($aliased));
        self::assertSame([$warning], ModelFixture::messages($wrapped));
        self::assertSame('final extends App\Dto\Pet', ModelFixture::hierarchy($aliased)['App\Dto\Cat']);
    }

    public function testWarnsAboutEachSubclassWhoseRequirementTheParentCannotEnforce(): void
    {
        $output = ModelFixture::build([
            'Animal' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
            'Pet' => ['allOf' => [['$ref' => '#/components/schemas/Animal', 'required' => ['name']], ['properties' => ['owner' => ['type' => 'string']]]]],
            'Kitten' => ['allOf' => [['$ref' => '#/components/schemas/Pet'], ['required' => ['name', 'owner'], 'properties' => ['age' => ['type' => 'integer']]]]],
        ]);
        $at = '/project/api/openapi.yaml#/components/schemas/';
        $tail = ', where extending cannot make it required; use "x-php-all-of: merge" to require it.';

        self::assertSame([
            "warning {$at}Pet: Required property \"name\" belongs to the parent App\\Dto\\Animal{$tail}",
            "warning {$at}Kitten: Required property \"name\" belongs to the parent App\\Dto\\Animal{$tail}",
            "warning {$at}Kitten: Required property \"owner\" belongs to the parent App\\Dto\\Pet{$tail}",
        ], ModelFixture::messages($output));
    }

    public function testReportsANonObjectBehindAnAliasAtTheMemberThatUsesIt(): void
    {
        $output = ModelFixture::build([
            'Email' => ['type' => 'string'],
            'Alias' => ['$ref' => '#/components/schemas/Email'],
            'Cat' => ['allOf' => [['$ref' => '#/components/schemas/Alias'], ['properties' => ['lives' => ['type' => 'integer']]]]],
            'Dog' => ['allOf' => [['$ref' => '#/components/schemas/Alias'], ['properties' => ['bark' => ['type' => 'boolean']]]]],
        ]);

        self::assertSame([
            'error /project/api/openapi.yaml#/components/schemas/Cat/allOf/0: An allOf member of a class must be an object schema.',
            'error /project/api/openapi.yaml#/components/schemas/Dog/allOf/0: An allOf member of a class must be an object schema.',
        ], ModelFixture::messages($output));
    }

    public function testTakesNothingFromAMemberThatIsNotAnObject(): void
    {
        $output = ModelFixture::build([
            'Cat' => ['allOf' => [
                ['type' => 'string', 'required' => ['lives'], 'allOf' => [['properties' => ['name' => ['type' => 'string']]]]],
                ['properties' => ['lives' => ['type' => 'integer']]],
            ]],
        ], [], ['*'], AllOfStrategy::MERGE);

        self::assertSame(['error /project/api/openapi.yaml#/components/schemas/Cat/allOf/0: An allOf member of a class must be an object schema.'], ModelFixture::messages($output));
        self::assertSame(['lives: int|null'], ModelFixture::classes($output)['App\Dto\Cat']);
    }

    public function testMergesOnlyWhatAnAliasNames(): void
    {
        $output = ModelFixture::build([
            'Pet' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
            'Alias' => ['$ref' => '#/components/schemas/Pet', 'allOf' => [['properties' => ['tag' => ['type' => 'string']]]]],
            'Cat' => ['allOf' => [['$ref' => '#/components/schemas/Alias'], ['properties' => ['lives' => ['type' => 'integer']]]]],
        ], [], ['*'], AllOfStrategy::MERGE);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(['name: string|null', 'lives: int|null'], ModelFixture::classes($output)['App\Dto\Cat']);
    }

    public function testTakesAVariantThroughAnAlias(): void
    {
        $cat = ['type' => 'object', 'properties' => ['petType' => ['type' => 'string'], 'lives' => ['type' => 'integer']]];
        $dog = ['type' => 'object', 'properties' => ['petType' => ['type' => 'string'], 'bark' => ['type' => 'boolean']]];
        $output = ModelFixture::build([
            'Pet' => [
                'oneOf' => [['$ref' => '#/components/schemas/Kitty'], ['$ref' => '#/components/schemas/Dog']],
                'discriminator' => ['propertyName' => 'petType', 'mapping' => ['dog' => '#/components/schemas/Puppy']],
            ],
            'Kitty' => ['$ref' => '#/components/schemas/Cat'],
            'Puppy' => ['$ref' => '#/components/schemas/Dog'],
            'Cat' => $cat,
            'Dog' => $dog,
        ]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame('abstract by petType {dog: App\Dto\Dog, Kitty: App\Dto\Cat}', ModelFixture::hierarchy($output)['App\Dto\Pet']);
    }

    public function testWarnsAboutAVariantListedDirectlyAndThroughAnAlias(): void
    {
        $output = ModelFixture::build([
            'Pet' => [
                'oneOf' => [['$ref' => '#/components/schemas/Cat'], ['$ref' => '#/components/schemas/Kitty']],
                'discriminator' => ['propertyName' => 'petType'],
            ],
            'Kitty' => ['$ref' => '#/components/schemas/Cat'],
            'Cat' => ['type' => 'object', 'properties' => ['petType' => ['type' => 'string']]],
        ]);

        self::assertSame(['warning /project/api/openapi.yaml#/components/schemas/Pet/oneOf/1: Variant App\\Dto\\Cat is listed twice.'], ModelFixture::messages($output));
    }

    public function testReportsAnAllOfMemberWhoseAliasesLoop(): void
    {
        $output = ModelFixture::build([
            'A' => ['allOf' => [['$ref' => '#/components/schemas/X'], ['properties' => ['a' => ['type' => 'string']]]]],
            'X' => ['$ref' => '#/components/schemas/Y'],
            'Y' => ['$ref' => '#/components/schemas/X'],
        ]);

        self::assertSame(['error /project/api/openapi.yaml#/components/schemas/A/allOf/0: The allOf chain loops back to a schema it is already merging.'], ModelFixture::messages($output));
        self::assertSame(['a: string|null'], ModelFixture::classes($output)['App\Dto\A']);
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

        // Other's integer discriminator cannot hold its implicit value, so its constructor checks nothing.
        self::assertSame(
            ['warning /project/api/openapi.yaml#/components/schemas/Other: Discriminator value "Other" is not a value of property "name", so it does not select App\Dto\Other.'],
            ModelFixture::messages($output),
        );
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

    public function testWarnsAboutASubclassTheMappingLeavesWithoutValue(): void
    {
        $output = ModelFixture::build([
            'Pet' => ['type' => 'object', 'properties' => ['kind' => ['type' => 'string']], 'discriminator' => ['propertyName' => 'kind', 'mapping' => ['Cat' => 'Dog']]],
            'Cat' => ['allOf' => [['$ref' => '#/components/schemas/Pet'], ['type' => 'object', 'properties' => ['lives' => ['type' => 'integer']]]]],
            'Dog' => ['allOf' => [['$ref' => '#/components/schemas/Pet'], ['type' => 'object', 'properties' => ['bark' => ['type' => 'string']]]]],
        ]);

        self::assertSame(
            ['warning ' . self::AT . 'Pet/discriminator: Variant App\\Dto\\Cat gets no discriminator value: the mapping gives "Cat" to another schema.'],
            ModelFixture::messages($output),
        );
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
                "error {$at}Empty/oneOf/0: An inline variant of a discriminated union needs one value of \"kind\", in \"const\" or a one-value \"enum\", as the mapping cannot name it.",
                "warning {$at}Cat: Variant App\\Dto\\Cat has no property \"kind\", which the discriminator reads.",
            ],
            ModelFixture::messages($output),
        );
    }

    public function testWarnsOnlyAboutDirectSubclassesOutsideTheVariants(): void
    {
        $at = self::AT;
        $output = ModelFixture::build([
            'Pet' => ['properties' => ['kind' => ['type' => 'string']], 'oneOf' => [['$ref' => '#/components/schemas/Cat']], 'discriminator' => ['propertyName' => 'kind']],
            'Cat' => ['allOf' => [self::PET, ['properties' => ['lives' => []]]]],
            'Kitten' => ['allOf' => [['$ref' => '#/components/schemas/Cat'], ['properties' => ['age' => []]]]],
            'Dog' => ['allOf' => [self::PET, ['properties' => ['bark' => []]]]],
            'Puppy' => ['allOf' => [['$ref' => '#/components/schemas/Dog'], ['properties' => ['age' => []]]]],
        ]);

        self::assertSame(
            ["warning {$at}Dog: App\\Dto\\Dog extends the discriminated base App\\Dto\\Pet but is not one of its variants, so it gets no discriminator value."],
            ModelFixture::messages($output),
        );
    }

    public function testAcceptsAVariantThatDescendsFromAnotherVariant(): void
    {
        $output = ModelFixture::build([
            'Pet' => ['oneOf' => [['$ref' => '#/components/schemas/Cat'], ['$ref' => '#/components/schemas/Dog']], 'discriminator' => ['propertyName' => 'kind']],
            'Cat' => ['type' => 'object', 'properties' => ['kind' => ['type' => 'string']]],
            'Dog' => ['allOf' => [['$ref' => '#/components/schemas/Cat'], ['properties' => ['bark' => []]]]],
        ]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(
            [
                'App\Dto\Pet' => 'abstract by kind {Cat: App\Dto\Cat, Dog: App\Dto\Dog}',
                'App\Dto\Cat' => 'open extends App\Dto\Pet',
                'App\Dto\Dog' => 'final extends App\Dto\Cat',
            ],
            ModelFixture::hierarchy($output),
        );
    }

    public function testReportsAMissingDiscriminatorPropertyOnceOnAnAllOfBase(): void
    {
        $at = self::AT;
        $output = ModelFixture::build([
            'Pet' => ['type' => 'object', 'properties' => ['a' => []], 'discriminator' => ['propertyName' => 'petType']],
            'Cat' => ['allOf' => [self::PET, ['properties' => ['c' => []]]]],
            'Dog' => ['allOf' => [self::PET, ['properties' => ['d' => []]]]],
        ]);

        self::assertSame(["warning {$at}Pet: App\\Dto\\Pet has no property \"petType\", which its discriminator reads."], ModelFixture::messages($output));
    }

    public function testReportsADiscriminatorPropertyExcludedBySkip(): void
    {
        $at = self::AT;
        $output = ModelFixture::build([
            'Pet' => ['oneOf' => [['$ref' => '#/components/schemas/Cat']], 'discriminator' => ['propertyName' => 'kind']],
            'Cat' => ['type' => 'object', 'properties' => ['kind' => ['type' => 'string', 'x-php-skip' => true], 'lives' => []]],
        ]);

        self::assertSame(["warning {$at}Cat: Variant App\\Dto\\Cat has no property \"kind\", which the discriminator reads."], ModelFixture::messages($output));
    }

    public function testChecksAListedBaseAfterAnAllOfBase(): void
    {
        $at = self::AT;
        $output = ModelFixture::build([
            'Vehicle' => ['type' => 'object', 'properties' => ['kind' => ['type' => 'string']], 'discriminator' => ['propertyName' => 'kind']],
            'Car' => ['allOf' => [['$ref' => '#/components/schemas/Vehicle'], ['properties' => ['doors' => ['type' => 'integer']]]]],
            'Pet' => ['oneOf' => [['$ref' => '#/components/schemas/Cat']], 'discriminator' => ['propertyName' => 'kind']],
            'Cat' => ['type' => 'object', 'properties' => ['lives' => []]],
        ]);

        self::assertSame(["warning {$at}Cat: Variant App\\Dto\\Cat has no property \"kind\", which the discriminator reads."], ModelFixture::messages($output));
    }
}
