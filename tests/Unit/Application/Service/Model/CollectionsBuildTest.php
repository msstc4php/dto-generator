<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Application\Service\Model;

use MSSTC4PHP\DtoGenerator\Application\Config\ViewSuffixes;
use MSSTC4PHP\DtoGenerator\Domain\Builder\CollectionClass;
use MSSTC4PHP\DtoGenerator\Domain\Builder\Collections;
use MSSTC4PHP\DtoGenerator\Domain\Model\CollectionType;
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
            'warning ' . self::AT . 'Basket/properties/anything: This list ' . sprintf($shape, 'mixed'),
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
        ]]], new Collections(CollectionClass::fromPattern('Doctrine\Common\Collections\ArrayCollection', ['int', 'item'])));

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(
            ['grid: Doctrine\Common\Collections\ArrayCollection<list<int>>', 'codes: Doctrine\Common\Collections\ArrayCollection<int|string>|null'],
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
            'reserved' => ['type' => 'array', 'items' => ['type' => 'object'], 'x-php-collection' => 'App\{item}'],
        ]]], null);

        self::assertSame([
            'error ' . self::AT . 'Basket/properties/number/x-php-collection: "x-php-collection" must be a class name or false.',
            'error ' . self::AT . 'Basket/properties/pattern/x-php-collection: "App\{type}" must be a class name, optionally with {item} for the short name of the item type, such as App\Collection\{item}List.',
            'warning ' . self::AT . 'Basket/properties/name/x-php-collection: "x-php-collection" applies to a list only; this property stays as it is.',
            'warning ' . self::AT . 'Basket/properties/reserved: This list stays an array: {item} in App\{item} stands for one class, enum or scalar item type, not array<array-key, mixed>.',
        ], ModelFixture::messages($output));
        self::assertSame(['number: list<string>|null', 'pattern: list<string>|null', 'name: string|null', 'reserved: list<array<array-key, mixed>>|null'], ModelFixture::classes($output)['App\Dto\Basket']);
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
                'error ' . self::AT . 'Odd/allOf/1/properties/ids: Property "ids" of App\Dto\Odd is list<string>|null here, but App\Collection\StringList<string>|null in an earlier allOf member.',
            ],
            ModelFixture::messages($output),
        );
        $classes = ModelFixture::classes($output);
        self::assertSame(['tags: App\Collection\DogTagsItemList<App\Dto\DogTagsItem>|null'], $classes['App\Dto\Dog']);
        self::assertSame(['App\Dto\DogTagsItem'], array_values(array_filter(ModelFixture::enums($output), static fn (string $enum): bool => strpos($enum, 'Dog') === 0 || strpos($enum, 'App\Dto\Dog') === 0)));
        self::assertSame(['codes: App\Collection\StringList<non-empty-string>|null'], $classes['App\Dto\Kid']);
    }

    private function pattern(): Collections
    {
        return new Collections(CollectionClass::fromPattern('App\Collection\{item}List'));
    }
}
