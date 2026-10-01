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
            ->discriminator(new Discriminator('kind', ['cat' => 'Cat']))
            ->build()
        ;

        self::assertSame(
            [
                ['#/components/schemas/Tag', '/properties/tag'],
                ['#/components/schemas/Item', '/properties/list/items'],
                ['#/components/schemas/Extra', '/additionalProperties'],
                ['base.yaml', '/allOf/0'],
                ['#/components/schemas/Cat', '/discriminator/mapping/cat'],
            ],
            array_map(static fn (ReferenceUse $use): array => [$use->ref(), $use->location()->pointer()], $schema->references()),
        );
    }

    public function testASchemaWithoutReferencesListsNone(): void
    {
        self::assertSame([], $this->builder()->types($this->type(SchemaType::STRING))->build()->references());
    }
}
