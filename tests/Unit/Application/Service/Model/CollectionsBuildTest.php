<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Application\Service\Model;

use MSSTC4PHP\DtoGenerator\Application\Config\ViewSuffixes;
use MSSTC4PHP\DtoGenerator\Application\Service\Model\Build\Output;
use MSSTC4PHP\DtoGenerator\Domain\Builder\CollectionClass;
use MSSTC4PHP\DtoGenerator\Domain\Builder\Collections;
use MSSTC4PHP\DtoGenerator\Domain\Model\ArgumentValue;
use MSSTC4PHP\DtoGenerator\Domain\Model\AttributeArgument;
use MSSTC4PHP\DtoGenerator\Domain\Model\AttributeModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Model\CollectionType;
use MSSTC4PHP\DtoGenerator\Domain\Model\NullableType;
use MSSTC4PHP\DtoGenerator\Domain\Shared\DefaultValue;
use MSSTC4PHP\DtoGenerator\Tests\Support\ModelFixture;
use PHPUnit\Framework\TestCase;

final class CollectionsBuildTest extends TestCase
{
    private const AT = '/project/api/openapi.yaml#/components/schemas/';

    private const TAG = ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]];

    public function testAPatternGivesAClassPerItemType(): void
    {
        $output = ModelFixture::collections([
            'Tag' => self::TAG,
            'Colour' => ['type' => 'string', 'enum' => ['red', 'green']],
            'Basket' => [
                'type' => 'object',
                'required' => ['tags'],
                'properties' => [
                    'tags' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Tag']],
                    'labels' => ['type' => 'array', 'items' => ['type' => 'string', 'minLength' => 1]],
                    'counts' => ['type' => 'array', 'items' => ['type' => 'integer']],
                    'prices' => ['type' => 'array', 'items' => ['type' => 'number']],
                    'flags' => ['type' => 'array', 'items' => ['type' => 'boolean']],
                    'colours' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Colour']],
                ],
            ],
        ], $this->pattern());

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame([
            'tags: App\Collection\TagList<App\Dto\Tag>',
            'labels: App\Collection\StringList<non-empty-string>|null',
            'counts: App\Collection\IntegerList<int>|null',
            'prices: App\Collection\FloatList<float>|null',
            'flags: App\Collection\BooleanList<bool>|null',
            'colours: App\Collection\ColourList<App\Dto\Colour>|null',
        ], ModelFixture::classes($output)['App\Dto\Basket']);
        self::assertArrayHasKey('App\Dto\Tag', ModelFixture::classes($output));
        self::assertSame(['App\Dto\Colour'], ModelFixture::enums($output));
    }

    public function testAListWithoutOneItemTypeStaysAnArray(): void
    {
        $output = ModelFixture::collections(['Basket' => ['type' => 'object', 'properties' => [
            'codes' => ['type' => 'array', 'items' => ['type' => ['integer', 'string']]],
            'notes' => ['type' => 'array', 'items' => ['type' => ['string', 'null']]],
            'grid' => ['type' => 'array', 'items' => ['type' => 'array', 'items' => ['type' => 'integer']]],
            'anything' => ['type' => 'array'],
        ]]], $this->pattern());

        $shape = 'stays an array: {item} in App\Collection\{item}List stands for one class, enum or scalar item type, not %s.';
        self::assertSame([
            'warning ' . self::AT . 'Basket/properties/codes: This list ' . sprintf($shape, 'int|string'),
            'warning ' . self::AT . 'Basket/properties/notes: This list ' . sprintf($shape, 'string|null'),
            'warning ' . self::AT . 'Basket/properties/grid: This list ' . sprintf($shape, 'list<int>'),
        ], ModelFixture::messages($output));
        self::assertSame([
            'codes: list<int|string>|null',
            'notes: list<string|null>|null',
            'grid: list<list<int>>|null',
            'anything: list<mixed>|null',
        ], ModelFixture::classes($output)['App\Dto\Basket']);
    }

    public function testAFixedClassHoldsAnyList(): void
    {
        $output = ModelFixture::collections(['Basket' => ['type' => 'object', 'required' => ['grid'], 'properties' => [
            'grid' => ['type' => 'array', 'items' => ['type' => 'array', 'items' => ['type' => 'integer']]],
            'codes' => ['type' => 'array', 'items' => ['type' => ['integer', 'string']]],
            'anything' => ['type' => 'array'],
        ]]], new Collections(CollectionClass::fromPattern('Doctrine\Common\Collections\ArrayCollection')->withTypeArguments(['int', 'item'])));

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(
            ['grid: Doctrine\Common\Collections\ArrayCollection<list<int>>', 'codes: Doctrine\Common\Collections\ArrayCollection<int|string>|null', 'anything: Doctrine\Common\Collections\ArrayCollection<mixed>|null'],
            ModelFixture::classes($output)['App\Dto\Basket'],
        );
        $grid = $output->classes()[0]->model()->property('grid');
        self::assertNotNull($grid);
        $type = $grid->type();
        self::assertInstanceOf(CollectionType::class, $type);
        self::assertSame(['int', 'item'], $type->typeArguments());
    }

    public function testOnlyAPropertyThatIsAListGetsACollection(): void
    {
        $output = ModelFixture::collections(['Basket' => ['type' => 'object', 'properties' => [
            'either' => ['oneOf' => [['type' => 'array', 'items' => ['type' => 'string']], ['type' => 'string']]],
            'byName' => ['type' => 'object', 'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']]],
        ]]], $this->pattern());

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(['either: list<string>|string|null', 'byName: array<array-key, list<string>>|null'], ModelFixture::classes($output)['App\Dto\Basket']);
    }

    public function testXPhpCollectionChoosesTheClassOfOneProperty(): void
    {
        $output = ModelFixture::collections([
            'Tag' => self::TAG,
            'Tags' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Tag'], 'x-php-collection' => 'App\Tags\{item}Set'],
            'Basket' => ['type' => 'object', 'properties' => [
                'tags' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Tag'], 'x-php-collection' => 'App\Bag'],
                'plain' => ['type' => 'array', 'items' => ['type' => 'string'], 'x-php-collection' => false],
                'aliased' => ['$ref' => '#/components/schemas/Tags'],
                'labels' => ['type' => 'array', 'items' => ['type' => 'string']],
            ]],
        ], $this->pattern());

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(
            ['tags: App\Bag<App\Dto\Tag>|null', 'plain: list<string>|null', 'aliased: App\Tags\TagSet<App\Dto\Tag>|null', 'labels: App\Collection\StringList<string>|null'],
            ModelFixture::classes($output)['App\Dto\Basket'],
        );
    }

    public function testXPhpCollectionWorksWithoutTheConfig(): void
    {
        $output = ModelFixture::collections(['Basket' => ['type' => 'object', 'properties' => [
            'labels' => ['type' => 'array', 'items' => ['type' => 'string'], 'x-php-collection' => 'App\Collection\{item}List'],
            'codes' => ['type' => 'array', 'items' => ['type' => 'integer'], 'x-php-collection' => 'App\Bag'],
            'plain' => ['type' => 'array', 'items' => ['type' => 'string']],
        ]]], null);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(['labels: App\Collection\StringList<string>|null', 'codes: App\Bag<int>|null', 'plain: list<string>|null'], ModelFixture::classes($output)['App\Dto\Basket']);
        $codes = $output->classes()[0]->model()->property('codes');
        self::assertNotNull($codes);
        self::assertSame('App\Bag<int>|null', $codes->type()->describe());
    }

    public function testReportsAMisusedXPhpCollection(): void
    {
        $output = ModelFixture::collections(['Basket' => ['type' => 'object', 'properties' => [
            'number' => ['type' => 'array', 'items' => ['type' => 'string'], 'x-php-collection' => 5],
            'pattern' => ['type' => 'array', 'items' => ['type' => 'string'], 'x-php-collection' => 'App\{type}'],
            'name' => ['type' => 'string', 'x-php-collection' => 'App\Bag'],
            'code' => ['type' => 'string', 'x-php-collection' => false],
            'count' => ['type' => 'integer', 'x-php-collection' => 5],
            'reserved' => ['type' => 'array', 'items' => ['type' => 'object'], 'x-php-collection' => 'App\{item}'],
        ]]], null);

        self::assertSame([
            'error ' . self::AT . 'Basket/properties/number/x-php-collection: "x-php-collection" must be a class name or false.',
            'error ' . self::AT . 'Basket/properties/pattern/x-php-collection: "App\{type}" must be a class name, optionally with {item} for the short name of the item type, such as App\Collection\{item}List.',
            'warning ' . self::AT . 'Basket/properties/name/x-php-collection: "x-php-collection" applies to a list only; this property stays as it is.',
            'warning ' . self::AT . 'Basket/properties/code/x-php-collection: "x-php-collection" applies to a list only; this property stays as it is.',
            'warning ' . self::AT . 'Basket/properties/count/x-php-collection: "x-php-collection" applies to a list only; this property stays as it is.',
            'warning ' . self::AT . 'Basket/properties/reserved: This list stays an array: {item} in App\{item} stands for one class, enum or scalar item type, not array<array-key, mixed>.',
        ], ModelFixture::messages($output));
        self::assertSame(['number: list<string>|null', 'pattern: list<string>|null', 'name: string|null', 'code: string|null', 'count: int|null', 'reserved: list<array<array-key, mixed>>|null'], ModelFixture::classes($output)['App\Dto\Basket']);
    }

    public function testAnItemNameThatMakesNoClassIsAnError(): void
    {
        $output = ModelFixture::collections([
            'Basket' => ['type' => 'object', 'properties' => ['strings' => ['type' => 'array', 'items' => ['type' => 'string'], 'x-php-collection' => 'App\{item}']]],
        ], null);

        self::assertSame(
            ['error ' . self::AT . 'Basket/properties/strings/x-php-collection: "App\{item}" gives "App\String" for the item type String, which is not a valid class name.'],
            ModelFixture::messages($output),
        );
    }

    public function testAnEmptyDefaultBecomesNullAndAnotherOneWarns(): void
    {
        $output = ModelFixture::collections(['Basket' => ['type' => 'object', 'properties' => [
            'empty' => ['type' => 'array', 'items' => ['type' => 'string'], 'default' => []],
            'filled' => ['type' => 'array', 'items' => ['type' => 'string'], 'default' => ['a']],
        ]]], $this->pattern());

        self::assertSame(
            ['warning ' . self::AT . 'Basket/properties/filled/default: A default for App\Collection\StringList<string> cannot be a PHP constant expression; null is used instead.'],
            ModelFixture::messages($output),
        );
        $defaults = [];
        foreach ($output->classes()[0]->model()->properties() as $property) {
            $default = $property->default();
            self::assertInstanceOf(DefaultValue::class, $default);
            $defaults[$property->name()] = $default->value();
        }
        self::assertSame(['empty' => null, 'filled' => null], $defaults);
    }

    public function testEachViewHoldsTheCollectionOfItsOwnModel(): void
    {
        $output = ModelFixture::collections([
            'Tag' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string', 'readOnly' => true], 'name' => ['type' => 'string']]],
            'Basket' => ['type' => 'object', 'properties' => ['tags' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Tag']]]],
        ], $this->pattern(), new ViewSuffixes());

        self::assertSame([], ModelFixture::messages($output));
        $classes = ModelFixture::classes($output);
        self::assertSame(['tags: App\Collection\TagReadList<App\Dto\TagRead>|null'], $classes['App\Dto\BasketRead']);
        self::assertSame(['tags: App\Collection\TagWriteList<App\Dto\TagWrite>|null'], $classes['App\Dto\BasketWrite']);
    }

    public function testAllOfMeetsTheListsOfTwoCollections(): void
    {
        $output = ModelFixture::collections([
            'Dog' => ['allOf' => [
                ['type' => 'object', 'properties' => ['tags' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['x']]]]],
                ['type' => 'object', 'properties' => ['tags' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['x', 'y']]]]],
            ]],
            'Base' => ['type' => 'object', 'properties' => ['codes' => ['type' => 'array', 'items' => ['type' => 'string']]]],
            'Kid' => ['allOf' => [['$ref' => '#/components/schemas/Base'], ['type' => 'object', 'properties' => ['codes' => ['type' => 'array', 'items' => ['type' => 'string', 'minLength' => 1]]]]]],
            'Odd' => ['allOf' => [
                ['type' => 'object', 'properties' => ['ids' => ['type' => 'array', 'items' => ['type' => 'string']]]],
                ['type' => 'object', 'properties' => ['ids' => ['type' => 'array', 'items' => ['type' => 'string'], 'x-php-collection' => false]]],
            ]],
        ], $this->pattern());

        self::assertSame(
            [
                'warning ' . self::AT . 'Dog/allOf/1/properties/tags/items: Class App\\Dto\\DogTagsItem is already generated from ' . self::AT . 'Dog/allOf/0/properties/tags/items, so this one becomes App\\Dto\\DogTagsItem2; set "x-php-class-name" to name it, in the document or "classNames" of the source.',
                'warning ' . self::AT . 'Kid/allOf/1/properties/codes: Property "codes" is declared again with a type PHP lets no subclass declare, so the members are merged instead of extending App\\Dto\\Base; "x-php-all-of: merge" says so, "extends" keeps the error.',
                'error ' . self::AT . 'Odd/allOf/1/properties/ids: Property "ids" of App\Dto\Odd stays an array by "x-php-collection: false" in one allOf member, but is App\Collection\StringList<string>|null in another.',
            ],
            ModelFixture::messages($output),
        );
        $classes = ModelFixture::classes($output);
        self::assertSame(['tags: App\Collection\DogTagsItemList<App\Dto\DogTagsItem>|null'], $classes['App\Dto\Dog']);
        self::assertSame(['App\Dto\DogTagsItem'], array_values(array_filter(ModelFixture::enums($output), static fn (string $enum): bool => strpos($enum, 'Dog') === 0 || strpos($enum, 'App\Dto\Dog') === 0)));
        self::assertSame(['codes: App\Collection\StringList<non-empty-string>|null'], $classes['App\Dto\Kid']);
    }

    public function testTypedCollectionMarksTheGeneratedItemClasses(): void
    {
        $output = ModelFixture::collections([
            'Tag' => self::TAG,
            'Note' => self::TAG,
            'Basket' => ['type' => 'object', 'properties' => [
                'tags' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Tag']],
                'moreTags' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Tag']],
                'notes' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Note'], 'x-php-collection' => 'App\\Notes\\{item}Bag'],
                'labels' => ['type' => 'array', 'items' => ['type' => 'string']],
                'money' => ['type' => 'array', 'items' => ['x-php-type' => 'App\\Money']],
                'plain' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Note'], 'x-php-collection' => false],
            ]],
        ], new Collections(CollectionClass::fromPattern('App\\Collection\\{item}List'), Collections::MUTABLE));

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(
            ['App\\Dto\\Tag' => ['MSSTC4PHP\DtoCollection\Attribute\TypedCollection(\'App\Collection\TagList\', MSSTC4PHP\DtoCollection\Attribute\Variant::Mutable)'], 'App\\Dto\\Note' => [], 'App\\Dto\\Basket' => []],
            $this->attributes($output),
        );
    }

    public function testWithoutTypedCollectionNoClassIsMarked(): void
    {
        $output = ModelFixture::collections([
            'Tag' => self::TAG,
            'Basket' => ['type' => 'object', 'properties' => ['tags' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Tag']]]],
        ], $this->pattern());

        self::assertSame(['App\\Dto\\Tag' => [], 'App\\Dto\\Basket' => []], $this->attributes($output));
    }

    public function testEachViewMarksItsOwnItemClass(): void
    {
        $output = ModelFixture::collections([
            'Tag' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string', 'readOnly' => true]]],
            'Basket' => ['type' => 'object', 'properties' => ['tags' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Tag']]]],
        ], new Collections(CollectionClass::fromPattern('App\\Collection\\{item}List'), Collections::IMMUTABLE), new ViewSuffixes());

        $attributes = $this->attributes($output);
        self::assertSame(['MSSTC4PHP\DtoCollection\Attribute\TypedCollection(\'App\Collection\TagReadList\', MSSTC4PHP\DtoCollection\Attribute\Variant::Immutable)'], $attributes['App\\Dto\\TagRead']);
        self::assertSame(['MSSTC4PHP\DtoCollection\Attribute\TypedCollection(\'App\Collection\TagWriteList\', MSSTC4PHP\DtoCollection\Attribute\Variant::Immutable)'], $attributes['App\\Dto\\TagWrite']);
    }

    public function testXPhpCollectionCarriesThroughTheWrappersOfAReference(): void
    {
        $tags = ['$ref' => '#/components/schemas/Tags'];
        $output = ModelFixture::collections([
            'Tag' => self::TAG,
            'Tags' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Tag'], 'x-php-collection' => false],
            'Basket' => ['type' => 'object', 'properties' => [
                'direct' => $tags,
                'described' => ['allOf' => [$tags], 'description' => 'The tags.'],
                'nullable' => ['oneOf' => [$tags, ['type' => 'null']]],
                'either' => ['anyOf' => [['type' => 'null'], $tags]],
            ]],
        ], $this->pattern());

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(
            ['direct: list<App\Dto\Tag>|null', 'described: list<App\Dto\Tag>|null', 'nullable: list<App\Dto\Tag>|null', 'either: list<App\Dto\Tag>|null'],
            ModelFixture::classes($output)['App\Dto\Basket'],
        );
    }

    /**
     * @return array<string, array{string|null}>
     */
    public static function allOfStrategies(): array
    {
        return ['extends' => [null], 'merge' => ['merge']];
    }

    /**
     * @dataProvider allOfStrategies
     */
    public function testAnAllOfMemberThatOnlyConstrainsAListKeepsItsCollection(?string $strategy): void
    {
        $basket = ['allOf' => [
            ['$ref' => '#/components/schemas/Base'],
            ['type' => 'object', 'properties' => ['tags' => ['type' => 'array', 'minItems' => 1]]],
        ]];
        $schemas = [
            'Tag' => self::TAG,
            'Base' => ['type' => 'object', 'properties' => ['tags' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Tag']]]],
            'Basket' => $strategy === null ? $basket : $basket + ['x-php-all-of' => $strategy],
        ];
        $plain = ModelFixture::collections($schemas, null);
        $output = ModelFixture::collections($schemas, $this->pattern());

        self::assertSame(str_replace('list<App\Dto\Tag>', 'App\Collection\TagList<App\Dto\Tag>', ModelFixture::messages($plain)), ModelFixture::messages($output));
        self::assertSame(
            str_replace('list<App\Dto\Tag>', 'App\Collection\TagList<App\Dto\Tag>', ModelFixture::classes($plain)['App\Dto\Basket']),
            ModelFixture::classes($output)['App\Dto\Basket'],
        );
    }

    public function testACollectionClassMayHoldOneItemTypeAndNameNoOtherClass(): void
    {
        $output = ModelFixture::collections([
            'Tag' => self::TAG,
            'TagList' => ['type' => 'object', 'properties' => ['page' => ['type' => 'integer']]],
            'Note' => self::TAG,
            'Basket' => ['type' => 'object', 'properties' => [
                'tags' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Tag']],
                'notes' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Note']],
                'vendorNotes' => ['type' => 'array', 'items' => ['type' => 'object', 'x-php-type' => 'Vendor\Note']],
                'labels' => ['type' => 'array', 'items' => ['type' => 'string']],
                'names' => ['type' => 'array', 'items' => ['type' => 'string', 'minLength' => 1]],
                'bag' => ['type' => 'array', 'items' => ['type' => 'string'], 'x-php-collection' => 'App\Bag'],
                'otherBag' => ['type' => 'array', 'items' => ['type' => 'integer'], 'x-php-collection' => 'App\Bag'],
            ]],
        ], new Collections(CollectionClass::fromPattern('App\Dto\{item}List')));

        self::assertSame([
            'error ' . self::AT . 'Basket/properties/tags: The collection class App\Dto\TagList is also generated from ' . self::AT . 'TagList; set "x-php-collection" on the property or rename one of them.',
            'error ' . self::AT . 'Basket/properties/vendorNotes: The collection class App\Dto\NoteList holds Vendor\Note here, but App\Dto\Note at ' . self::AT . 'Basket/properties/notes; set "x-php-collection" on one of them.',
        ], ModelFixture::messages($output));
    }

    public function testXPhpCollectionKeepsTheConfiguredTypeArguments(): void
    {
        $output = ModelFixture::collections([
            'Tag' => self::TAG,
            'Basket' => ['type' => 'object', 'properties' => [
                'tags' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Tag'], 'x-php-collection' => 'App\Tags\{item}Set'],
            ]],
        ], new Collections(CollectionClass::fromPattern('App\Collection\{item}List')->withTypeArguments(['int', 'item'])));

        self::assertSame([], ModelFixture::messages($output));
        $tags = $output->classes()[1]->model()->property('tags');
        self::assertNotNull($tags);
        $type = $tags->type();
        self::assertSame('App\Tags\TagSet<App\Dto\Tag>|null', $type->describe());
        $inner = $type instanceof NullableType ? $type->inner() : $type;
        self::assertInstanceOf(CollectionType::class, $inner);
        self::assertSame(['int', 'item'], $inner->typeArguments());
    }

    public function testAnAliasUsedTwiceIsReportedWhereEachPropertyUsesIt(): void
    {
        $output = ModelFixture::collections([
            'Codes' => ['type' => 'array', 'items' => ['type' => ['integer', 'string']], 'x-php-collection' => 'App\{item}List'],
            'Basket' => ['type' => 'object', 'properties' => [
                'first' => ['$ref' => '#/components/schemas/Codes'],
                'second' => ['$ref' => '#/components/schemas/Codes'],
            ]],
        ], null);

        $warning = 'This list stays an array: {item} in App\{item}List stands for one class, enum or scalar item type, not int|string.';
        self::assertSame(
            ['warning ' . self::AT . 'Basket/properties/first: ' . $warning, 'warning ' . self::AT . 'Basket/properties/second: ' . $warning],
            ModelFixture::messages($output),
        );
    }

    public function testAllOfMembersThatChooseTwoClassesForOneListAreAnError(): void
    {
        $output = ModelFixture::collections(['Odd' => ['allOf' => [
            ['type' => 'object', 'properties' => ['ids' => ['type' => 'array', 'items' => ['type' => 'string']]]],
            ['type' => 'object', 'properties' => ['ids' => ['type' => 'array', 'items' => ['type' => 'string'], 'x-php-collection' => 'App\Bag']]],
        ]]], $this->pattern());

        self::assertSame(
            ['error ' . self::AT . 'Odd/allOf/1/properties/ids: Property "ids" of App\Dto\Odd is App\Bag<string>|null here, but App\Collection\StringList<string>|null in an earlier allOf member.'],
            ModelFixture::messages($output),
        );
    }

    public function testABadXPhpCollectionKeepsTheArrayAlsoBesideTheConfig(): void
    {
        $output = ModelFixture::collections(['Basket' => ['type' => 'object', 'properties' => [
            'labels' => ['type' => 'array', 'items' => ['type' => 'string'], 'x-php-collection' => 'App\{type}'],
        ]]], $this->pattern());

        self::assertCount(1, ModelFixture::messages($output));
        self::assertSame(['labels: list<string>|null'], ModelFixture::classes($output)['App\Dto\Basket']);
    }

    public function testAnAllOfMemberMayDeclineTheCollectionFirst(): void
    {
        $output = ModelFixture::collections(['Odd' => ['allOf' => [
            ['type' => 'object', 'properties' => ['ids' => ['type' => 'array', 'items' => ['type' => 'string'], 'x-php-collection' => false]]],
            ['type' => 'object', 'properties' => ['ids' => ['type' => 'array', 'items' => ['type' => 'string']]]],
            ['type' => 'object', 'properties' => ['ids' => ['type' => 'array', 'items' => ['type' => 'string']]]],
        ]]], $this->pattern());

        self::assertSame(
            ['error ' . self::AT . 'Odd/allOf/1/properties/ids: Property "ids" of App\Dto\Odd stays an array by "x-php-collection: false" in one allOf member, but is App\Collection\StringList<string>|null in another.'],
            ModelFixture::messages($output),
        );
    }

    public function testAnUntypedFirstMemberTakesTheCollectionOfALaterOne(): void
    {
        $output = ModelFixture::collections([
            'Tag' => self::TAG,
            'Basket' => ['x-php-all-of' => 'merge', 'allOf' => [
                ['type' => 'object', 'properties' => ['tags' => ['type' => 'array', 'minItems' => 1]]],
                ['type' => 'object', 'properties' => ['tags' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Tag']]]],
            ]],
        ], $this->pattern());

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(['tags: App\Collection\TagList<App\Dto\Tag>|null'], ModelFixture::classes($output)['App\Dto\Basket']);
    }

    public function testAMemberThatNarrowsTheItemsKeepsAGenericClassOnly(): void
    {
        $output = ModelFixture::collections([
            'Generic' => ['x-php-all-of' => 'merge', 'allOf' => [
                ['type' => 'object', 'properties' => ['ids' => ['type' => 'array', 'items' => ['type' => ['integer', 'string']], 'x-php-collection' => 'App\Bag']]],
                ['type' => 'object', 'properties' => ['ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'x-php-collection' => false]]],
            ]],
            'Typed' => ['x-php-all-of' => 'merge', 'allOf' => [
                ['type' => 'object', 'properties' => ['ids' => ['type' => 'array', 'items' => ['type' => ['integer', 'string']], 'x-php-collection' => 'App\Bag']]],
                ['type' => 'object', 'properties' => ['ids' => ['type' => 'array', 'items' => ['type' => 'integer']]]],
            ]],
            'Bound' => ['x-php-all-of' => 'merge', 'allOf' => [
                ['type' => 'object', 'properties' => ['ids' => ['type' => 'array', 'items' => ['type' => 'string'], 'x-php-collection' => 'App\{item}Set']]],
                ['type' => 'object', 'properties' => ['ids' => ['type' => 'array', 'items' => ['type' => ['string', 'null']]]]],
            ]],
        ], null);

        self::assertSame(
            ['error ' . self::AT . 'Generic/allOf/1/properties/ids: Property "ids" of App\Dto\Generic stays an array by "x-php-collection: false" in one allOf member, but is App\Bag<int>|null in another.'],
            ModelFixture::messages($output),
        );
        self::assertSame(['ids: App\Bag<int>|null'], ModelFixture::classes($output)['App\Dto\Typed']);
        self::assertSame(['ids: App\StringSet<string>|null'], ModelFixture::classes($output)['App\Dto\Bound']);
    }

    public function testAnExtendingMemberThatDeclinesTheParentsCollectionIsAnError(): void
    {
        $output = ModelFixture::collections([
            'Tag' => self::TAG,
            'Base' => ['type' => 'object', 'properties' => ['tags' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Tag']], 'note' => ['type' => 'string']]],
            'Basket' => ['allOf' => [
                ['$ref' => '#/components/schemas/Base'],
                ['type' => 'object', 'properties' => [
                    'tags' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Tag'], 'x-php-collection' => false],
                    'note' => ['type' => 'integer'],
                ]],
            ]],
        ], $this->pattern());

        self::assertSame(
            [
                'error ' . self::AT . 'Basket/allOf/1/properties/tags: Property "tags" of App\Dto\Basket stays an array by "x-php-collection: false" in one allOf member, but is App\Collection\TagList<App\Dto\Tag>|null in another.',
                'error ' . self::AT . 'Basket/allOf/1/properties/note: Property "note" of App\Dto\Basket is already declared by App\Dto\Base.',
            ],
            ModelFixture::messages($output),
        );
    }

    public function testAnExplicitClassIsNoMeetOfAnotherPattern(): void
    {
        $output = ModelFixture::collections([
            'Tag' => self::TAG,
            'SpecialTag' => ['allOf' => [['$ref' => '#/components/schemas/Tag'], ['type' => 'object', 'properties' => ['level' => ['type' => 'integer']]]]],
            'Basket' => ['x-php-all-of' => 'merge', 'allOf' => [
                ['type' => 'object', 'properties' => ['tags' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Tag'], 'x-php-collection' => 'App\Tags\{item}Bag']]],
                ['type' => 'object', 'properties' => ['tags' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/SpecialTag']]]],
            ]],
            'Narrowed' => ['x-php-all-of' => 'merge', 'allOf' => [
                ['type' => 'object', 'properties' => ['tags' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Tag']]]],
                ['type' => 'object', 'properties' => ['tags' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/SpecialTag']]]],
            ]],
        ], $this->pattern());

        self::assertSame(
            ['error ' . self::AT . 'Basket/allOf/1/properties/tags: Property "tags" of App\Dto\Basket is App\Collection\SpecialTagList<App\Dto\SpecialTag>|null here, but App\Tags\TagBag<App\Dto\Tag>|null in an earlier allOf member.'],
            ModelFixture::messages($output),
        );
        self::assertSame(['tags: App\Collection\SpecialTagList<App\Dto\SpecialTag>|null'], ModelFixture::classes($output)['App\Dto\Narrowed']);
    }

    public function testBelowPhp81AnEnumSharesTheCollectionOfItsScalar(): void
    {
        $output = ModelFixture::collections([
            'Colour' => ['type' => 'string', 'enum' => ['red']],
            'Size' => ['type' => 'integer', 'enum' => [1, 2]],
            'Basket' => ['type' => 'object', 'properties' => [
                'labels' => ['type' => 'array', 'items' => ['type' => 'string']],
                'colours' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Colour']],
                'counts' => ['type' => 'array', 'items' => ['type' => 'integer']],
                'sizes' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Size']],
            ]],
        ], $this->pattern(), null, '8.0');

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(
            ['labels: App\Collection\StringList<string>|null', 'colours: App\Collection\StringList<App\Dto\Colour>|null', 'counts: App\Collection\IntegerList<int>|null', 'sizes: App\Collection\IntegerList<App\Dto\Size>|null'],
            ModelFixture::classes($output)['App\Dto\Basket'],
        );
    }

    public function testAClassNamedInAnotherCaseIsTheSameItem(): void
    {
        $output = ModelFixture::collections(['Basket' => ['type' => 'object', 'properties' => [
            'prices' => ['type' => 'array', 'items' => ['type' => 'object', 'x-php-type' => 'Vendor\Money'], 'x-php-collection' => 'App\Prices'],
            'costs' => ['type' => 'array', 'items' => ['type' => 'object', 'x-php-type' => 'vendor\money'], 'x-php-collection' => 'App\Prices'],
        ]]], new Collections(CollectionClass::fromPattern('App\{item}List')->withTypeArguments([])));

        self::assertSame([], ModelFixture::messages($output));
    }

    public function testAMixedTypeGetsNoSecondWarningFromXPhpCollection(): void
    {
        $output = ModelFixture::collections([
            'Tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'x-php-collection' => 'App\Bag'],
            'Basket' => ['type' => 'object', 'properties' => ['tags' => ['allOf' => [['$ref' => '#/components/schemas/Tags'], ['type' => 'object']]]]],
        ], null);

        self::assertSame(
            ['error ' . self::AT . 'Basket/properties/tags: An "allOf" member requires an object beside one that can be no object, so no value passes; drop one of them.'],
            ModelFixture::messages($output),
        );
    }

    public function testEachClashIsReportedOnceAndEveryPropertyIsChecked(): void
    {
        $output = ModelFixture::collections([
            'Tag' => self::TAG,
            'TagList' => ['type' => 'object', 'properties' => ['page' => ['type' => 'integer']]],
            'Note' => self::TAG,
            'Basket' => ['type' => 'object', 'properties' => [
                'tags' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Tag']],
                'moreTags' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Tag']],
                'bag' => ['type' => 'array', 'items' => ['type' => 'string'], 'x-php-collection' => 'App\Bag'],
                'notes' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Note']],
                'vendorNotes' => ['type' => 'array', 'items' => ['type' => 'object', 'x-php-type' => 'Vendor\Note']],
                'otherNotes' => ['type' => 'array', 'items' => ['type' => 'object', 'x-php-type' => 'Other\Note']],
            ]],
        ], new Collections(CollectionClass::fromPattern('App\Dto\{item}List')));

        self::assertSame([
            'error ' . self::AT . 'Basket/properties/tags: The collection class App\Dto\TagList is also generated from ' . self::AT . 'TagList; set "x-php-collection" on the property or rename one of them.',
            'error ' . self::AT . 'Basket/properties/vendorNotes: The collection class App\Dto\NoteList holds Vendor\Note here, but App\Dto\Note at ' . self::AT . 'Basket/properties/notes; set "x-php-collection" on one of them.',
        ], ModelFixture::messages($output));
    }

    public function testTheConfiguredClassIsMatchedForAnItemWhoseNameMakesNoClass(): void
    {
        $output = ModelFixture::collections([
            'Arr' => self::TAG,
            'Basket' => ['type' => 'object', 'properties' => [
                'items' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Arr'], 'x-php-collection' => 'App\Bag'],
            ]],
        ], new Collections(CollectionClass::fromPattern('App\{item}ay'), Collections::IMMUTABLE));

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(['App\Dto\Arr' => [], 'App\Dto\Basket' => []], $this->attributes($output));
    }

    /**
     * @return array<string, list<string>>
     */
    private function attributes(Output $output): array
    {
        $attributes = [];
        foreach ($output->classes() as $class) {
            $attributes[$class->model()->name()->fqcn()] = array_map(static function (AttributeModel $attribute): string {
                $arguments = array_map(static function (AttributeArgument $argument): string {
                    $value = $argument->value();

                    return $value->kind() === ArgumentValue::KIND_CONSTANT
                        ? sprintf('%s::%s', $value->constantClass() instanceof ClassName ? $value->constantClass()->fqcn() : '', $value->constantName())
                        : "'" . (is_string($value->literalValue()) ? $value->literalValue() : '') . "'";
                }, $attribute->arguments());

                return sprintf('%s(%s)', $attribute->className()->fqcn(), implode(', ', $arguments));
            }, $class->model()->attributes());
        }

        return $attributes;
    }

    private function pattern(): Collections
    {
        return new Collections(CollectionClass::fromPattern('App\Collection\{item}List'));
    }
}
