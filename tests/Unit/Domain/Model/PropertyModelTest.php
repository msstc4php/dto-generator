<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Model;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\AttributeModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Model\DocModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\NullableType;
use MSSTC4PHP\DtoGenerator\Domain\Model\PropertyModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ScalarType;
use MSSTC4PHP\DtoGenerator\Domain\Model\TypeModel;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use MSSTC4PHP\DtoGenerator\Domain\Shared\DefaultValue;
use PHPUnit\Framework\TestCase;

final class PropertyModelTest extends TestCase
{
    public function testExposesItsParts(): void
    {
        $default = new DefaultValue('guest');
        $doc = new DocModel('Login name');
        $source = new SchemaLocation('a.json', '/properties/user_name');
        $property = new PropertyModel('userName', 'user_name', ScalarType::string(), false, $default, $doc, $source);

        self::assertSame('userName', $property->name());
        self::assertSame('user_name', $property->wireName());
        self::assertSame('string', $property->type()->describe());
        self::assertFalse($property->isRequired());
        self::assertSame($default, $property->default());
        self::assertSame($doc, $property->doc());
        self::assertSame($source, $property->source());
        self::assertSame([], $property->attributes());
        self::assertFalse($property->isNullable());
    }

    public function testKnowsWhenItIsNullable(): void
    {
        self::assertTrue($this->property('email', new NullableType(ScalarType::string()))->isNullable());
    }

    public function testWithAttributesAppendsWithoutTouchingTheOriginal(): void
    {
        $first = new AttributeModel(ClassName::fromFqcn('App\First'));
        $second = new AttributeModel(ClassName::fromFqcn('App\Second'));
        $original = $this->property('email')->withAttributes($first);
        $extended = $original->withAttributes($second);

        self::assertSame([$first], $original->attributes());
        self::assertSame([$first, $second], $extended->attributes());
    }

    /**
     * @dataProvider invalidNames
     */
    public function testRejectsInvalidPropertyNames(string $name): void
    {
        $this->expectException(InvalidModel::class);

        $this->property($name);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidNames(): array
    {
        return [
            'empty' => [''],
            'dash' => ['user-name'],
            'leading digit' => ['2fa'],
            'this' => ['this'],
        ];
    }

    public function testRejectsAnEmptyWireName(): void
    {
        $this->expectException(InvalidModel::class);

        new PropertyModel('email', '', ScalarType::string(), true, null, DocModel::none(), new SchemaLocation('a.json'));
    }

    private function property(string $name, ?TypeModel $type = null): PropertyModel
    {
        return new PropertyModel(
            $name,
            $name === '' ? 'empty' : $name,
            $type ?? ScalarType::string(),
            true,
            null,
            DocModel::none(),
            new SchemaLocation('a.json'),
        );
    }
}
