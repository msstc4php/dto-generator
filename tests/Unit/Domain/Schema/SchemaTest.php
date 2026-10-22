<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Schema;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Discriminator;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Extensions;
use MSSTC4PHP\DtoGenerator\Domain\Schema\ReferenceUse;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaBuilder;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaType;
use PHPUnit\Framework\TestCase;

final class SchemaTest extends TestCase
{
    public function testExposesItsLocation(): void
    {
        self::assertSame('a.json#/p', $this->builder('/p')->build()->location()->toString());
    }

    public function testNonNullTypesIsAListWhenNullComesFirst(): void
    {
        $schema = $this->builder()->types(self::type(SchemaType::NULL), self::type(SchemaType::STRING))->build();

        self::assertSame([self::type(SchemaType::STRING)], $schema->nonNullTypes());
    }

    public function testNumericKeywordNamesStayStrings(): void
    {
        $schema = $this->builder()->keyword('200', 1)->build();

        self::assertTrue($schema->hasKeyword('200'));
        self::assertSame(1, $schema->keyword('200'));
        self::assertSame(['200'], $schema->keywordNames());
    }

    public function testAcceptsKeywordsThatMerelyStartWithX(): void
    {
        self::assertSame(1, $this->builder()->keyword('xmlLength', 1)->build()->keyword('xmlLength'));
    }

    public function testDetectsNullableTypes(): void
    {
        $schema = $this->builder()->types($this->type(SchemaType::STRING), $this->type(SchemaType::NULL))->build();

        self::assertTrue($schema->isNullable());
        self::assertTrue($schema->hasType($this->type(SchemaType::STRING)));
        self::assertSame([$this->type(SchemaType::STRING)], $schema->nonNullTypes());
    }

    public function testASchemaWithOnlyNonNullTypesIsNotNullable(): void
    {
        self::assertFalse($this->builder()->types($this->type(SchemaType::STRING))->build()->isNullable());
    }

    public function testASchemaWithoutTypesIsNotNullable(): void
    {
        $schema = $this->builder()->build();

        self::assertFalse($schema->isNullable());
        self::assertSame([], $schema->types());
    }

    public function testKeepsPropertyOrderAndRequiredSet(): void
    {
        $string = $this->builder('/p')->types($this->type(SchemaType::STRING))->build();
        $schema = $this->builder()
            ->types($this->type(SchemaType::OBJECT))
            ->property('id', $string)
            ->property('email', $string)
            ->required('id')
            ->build()
        ;

        self::assertSame(['id', 'email'], $schema->propertyNames());
        self::assertSame($string, $schema->property('email'));
        self::assertNull($schema->property('missing'));
        self::assertTrue($schema->isRequired('id'));
        self::assertFalse($schema->isRequired('email'));
        self::assertSame(['id'], $schema->required());
    }

    public function testNumericPropertyNamesStayStrings(): void
    {
        $string = $this->builder('/p')->types($this->type(SchemaType::STRING))->build();
        $schema = $this->builder()->property('200', $string)->property('ok', $string)->build();

        self::assertSame(['200', 'ok'], $schema->propertyNames());
        self::assertSame($string, $schema->property('200'));
    }

    public function testDistinguishesANullDefaultFromNoDefault(): void
    {
        $withNull = $this->builder()->default(null)->build();
        $withoutDefault = $this->builder()->build();

        self::assertNotNull($withNull->default());
        self::assertNull($withNull->default()->value());
        self::assertNull($withoutDefault->default());
    }

    public function testExposesScalarKeywordsAndReferences(): void
    {
        $schema = $this->builder()
            ->ref('#/components/schemas/User')
            ->format('email')
            ->description('Primary e-mail')
            ->deprecated()
            ->enum(['a', 'b'])
            ->keyword('minLength', 3)
            ->extensions(new Extensions(['x-php-name' => 'mail']))
            ->build()
        ;

        self::assertSame('#/components/schemas/User', $schema->ref());
        self::assertSame('email', $schema->format());
        self::assertSame('Primary e-mail', $schema->description());
        self::assertTrue($schema->isDeprecated());
        self::assertSame(['a', 'b'], $schema->enum());
        self::assertTrue($schema->hasKeyword('minLength'));
        self::assertSame(3, $schema->keyword('minLength'));
        self::assertSame(['minLength' => 3], $schema->keywords());
        self::assertSame('mail', $schema->extensions()->get('x-php-name'));
    }

    public function testExposesComposition(): void
    {
        $part = $this->builder('/part')->build();
        $discriminator = new Discriminator('kind');
        $schema = $this->builder()
            ->allOf($part)
            ->oneOf($part, $part)
            ->anyOf($part)
            ->items($part)
            ->additionalProperties($part)
            ->discriminator($discriminator)
            ->build()
        ;

        self::assertSame([$part], $schema->allOf());
        self::assertSame([$part, $part], $schema->oneOf());
        self::assertSame([$part], $schema->anyOf());
        self::assertSame($part, $schema->items());
        self::assertSame($part, $schema->additionalProperties());
        self::assertSame($discriminator, $schema->discriminator());
        $without = $schema->withoutDiscriminator();
        self::assertNull($without->discriminator());
        self::assertSame($discriminator, $schema->discriminator());
        self::assertSame([$part, $part], $without->oneOf());
    }

    public function testAdditionalPropertiesAcceptsBooleans(): void
    {
        self::assertFalse($this->builder()->additionalProperties(false)->build()->additionalProperties());
        self::assertNull($this->builder()->build()->additionalProperties());
    }

    public function testRejectsDuplicateTypes(): void
    {
        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage('repeats type "string"');

        $this->builder()->types($this->type(SchemaType::STRING), $this->type(SchemaType::STRING))->build();
    }

    public function testRejectsAnEmptyEnum(): void
    {
        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage('has an empty "enum"');

        $this->builder()->enum([])->build();
    }

    public function testRejectsDuplicateRequiredNames(): void
    {
        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage('repeats a name in "required"');

        $this->builder()->required('id', 'id')->build();
    }

    /**
     * @dataProvider reservedKeywords
     */
    public function testRejectsKeywordsThatHaveTheirOwnAccessor(string $keyword): void
    {
        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage('has a dedicated field');

        $this->builder()->keyword($keyword, 'x')->build();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function reservedKeywords(): array
    {
        $cases = ['extension' => ['x-php-name']];
        foreach (Schema::STRUCTURAL_KEYWORDS as $keyword) {
            $cases[$keyword] = [$keyword];
        }

        return $cases;
    }

    public function testKeywordRejectsAMissingName(): void
    {
        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage('Keyword "pattern" is not set');

        $this->builder()->build()->keyword('pattern');
    }

    private function builder(string $pointer = ''): SchemaBuilder
    {
        return new SchemaBuilder(new SchemaLocation('a.json', $pointer));
    }

    private function type(string $type): SchemaType
    {
        return SchemaType::from($type);
    }

    public function testListsEveryReferenceWithItsLocation(): void
    {
        $tag = $this->builder('/properties/tag')->ref('#/components/schemas/Tag')->build();
        $item = $this->builder('/properties/list/items')->ref('#/components/schemas/Item')->build();
        $list = $this->builder('/properties/list')->types($this->type(SchemaType::ARRAY))->items($item)->build();
        $base = $this->builder('/allOf/0')->ref('base.yaml')->build();
        $extra = $this->builder('/additionalProperties')->ref('#/components/schemas/Extra')->build();
        $schema = $this->builder()
            ->property('tag', $tag)
            ->property('list', $list)
            ->allOf($base)
            ->additionalProperties($extra)
            ->discriminator(new Discriminator('kind', ['cat' => 'Cat'], 'Other'))
            ->build()
        ;

        self::assertSame(
            [
                ['#/components/schemas/Tag', '/properties/tag'],
                ['#/components/schemas/Item', '/properties/list/items'],
                ['#/components/schemas/Extra', '/additionalProperties'],
                ['base.yaml', '/allOf/0'],
                ['#/components/schemas/Cat', '/discriminator/mapping/cat'],
                ['#/components/schemas/Other', '/discriminator/defaultMapping'],
            ],
            array_map(static fn (ReferenceUse $use): array => [$use->ref(), $use->location()->pointer()], $schema->references()),
        );
    }

    public function testASchemaWithoutReferencesListsNone(): void
    {
        $schema = $this->builder()->types($this->type(SchemaType::STRING))->build();

        self::assertSame([], $schema->references());
        self::assertSame([], $schema->keywordReferences());
    }

    public function testListsTheReferencesInTheSchemasOfItsKeywords(): void
    {
        $tags = $this->builder('/properties/tags')
            ->keyword('contains', ['$ref' => '#/C'])
            ->keyword('prefixItems', [['$ref' => '#/P0'], ['allOf' => [['items' => ['$ref' => '#/I']]], 'anyOf' => 'none'], true])
            ->build()
        ;
        $schema = $this->builder()
            ->ref('#/Base')
            ->keyword('propertyNames', ['$ref' => '#/N', 'not' => ['properties' => ['x' => ['$ref' => '#/X']]]])
            ->keyword('patternProperties', ['^a/~' => ['$ref' => '#/A'], 'b' => false])
            ->keyword('dependentSchemas', ['a' => ['if' => ['$ref' => '#/If'], 'then' => ['oneOf' => [['$ref' => '#/T']]]]])
            ->keyword('if', ['additionalProperties' => ['$ref' => '#/AP'], 'unevaluatedItems' => ['$ref' => '#/U'], 'additionalItems' => ['$ref' => '#/AI']])
            ->keyword('else', ['else' => ['unevaluatedProperties' => ['$ref' => '#/UP']], '$ref' => 5, 'propertyNames' => ['$ref' => '']])
            ->keyword('const', ['$ref' => '#/Data'])
            ->keyword('examples', [['$ref' => '#/Data']])
            ->keyword('dependentRequired', ['a' => ['$ref']])
            ->keyword('then', [['$ref' => '#/List']])
            ->keyword('contentSchema', ['$ref' => '#/Content'])
            ->keyword('dependencies', ['a' => ['b'], 'c' => ['$ref' => '#/Dep']])
            ->keyword('contains', ['items' => [['$ref' => '#/Tuple0'], true]])
            ->property('tags', $tags)
            ->build()
        ;

        self::assertSame([
            'a.json#/if/unevaluatedItems #/U',
            'a.json#/if/additionalProperties #/AP',
            'a.json#/if/additionalItems #/AI',
            'a.json#/else/else/unevaluatedProperties #/UP',
            'a.json#/contains/items/0 #/Tuple0',
            'a.json#/propertyNames #/N',
            'a.json#/propertyNames/not/properties/x #/X',
            'a.json#/contentSchema #/Content',
            'a.json#/patternProperties/^a~1~0 #/A',
            'a.json#/dependentSchemas/a/if #/If',
            'a.json#/dependentSchemas/a/then/oneOf/0 #/T',
            'a.json#/dependencies/c #/Dep',
            'a.json#/properties/tags/contains #/C',
            'a.json#/properties/tags/prefixItems/0 #/P0',
            'a.json#/properties/tags/prefixItems/1/allOf/0/items #/I',
        ], array_map(static fn (ReferenceUse $use): string => $use->location()->toString() . ' ' . $use->ref(), $schema->keywordReferences()));
        self::assertCount(1, $schema->references());
    }

    public function testRequirePropertyRejectsAnUnknownName(): void
    {
        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage('has no property "missing"');

        $this->builder()->build()->requireProperty('missing');
    }

    public function testRequirePropertyReturnsTheSchema(): void
    {
        $id = $this->builder('/id')->build();

        self::assertSame($id, $this->builder()->property('id', $id)->build()->requireProperty('id'));
    }

    /**
     * @return iterable<string, array{list<string>, bool}>
     */
    public static function subschemaPaths(): iterable
    {
        yield 'the schema itself' => [[], true];
        yield 'a property' => [['properties', 'a'], true];
        yield 'a nested member' => [['allOf', '0', 'properties', 'a', 'items', 'not'], true];
        yield 'an item of a list of items' => [['items', '1', 'additionalProperties'], true];
        yield 'the items' => [['items'], true];
        yield 'within the items' => [['items', 'properties', 'a'], true];
        yield 'the map of properties' => [['properties'], false];
        yield 'the list of members' => [['allOf'], false];
        yield 'a default' => [['properties', 'a', 'default'], false];
        yield 'within a constant' => [['const', 'properties', 'a'], false];
    }

    /**
     * @dataProvider subschemaPaths
     *
     * @param list<string> $segments
     */
    public function testTellsAPathToASubschema(array $segments, bool $expected): void
    {
        self::assertSame($expected, Schema::leadsToASubschema($segments));
    }
}
