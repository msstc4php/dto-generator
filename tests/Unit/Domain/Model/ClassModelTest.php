<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Model;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\AttributeModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassKind;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Model\DiscriminatorModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\DocModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\PropertyModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ScalarType;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use MSSTC4PHP\DtoGenerator\Domain\Target\Mutability;
use PHPUnit\Framework\TestCase;

final class ClassModelTest extends TestCase
{
    public function testExposesItsParts(): void
    {
        $id = $this->property('id');
        $parent = ClassName::fromFqcn('App\Base');
        $class = new ClassModel(
            ClassName::fromFqcn('App\User'),
            ClassKind::from(ClassKind::FINAL),
            $parent,
            [$id],
            Mutability::from(Mutability::IMMUTABLE),
            new DocModel('A user'),
            new SchemaLocation('a.json', '/components/schemas/User'),
        );

        self::assertSame('App\User', $class->name()->fqcn());
        self::assertSame(ClassKind::from(ClassKind::FINAL), $class->kind());
        self::assertSame($parent, $class->parent());
        self::assertSame([$id], $class->properties());
        self::assertSame($id, $class->property('id'));
        self::assertNull($class->property('missing'));
        self::assertSame(Mutability::from(Mutability::IMMUTABLE), $class->mutability());
        self::assertSame('A user', $class->doc()->description());
        self::assertSame('a.json#/components/schemas/User', $class->source()->toString());
        self::assertSame([], $class->attributes());
        self::assertNull($class->discriminator());
    }

    public function testRejectsDuplicatePropertyNames(): void
    {
        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage('declares property "id" twice');

        $this->classWith([$this->property('id', 'id'), $this->property('id', 'ID')]);
    }

    public function testRejectsDuplicateWireNames(): void
    {
        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage('maps wire name "200" twice');

        $this->classWith([$this->property('ok', '200'), $this->property('success', '200')]);
    }

    public function testRejectsExtendingItself(): void
    {
        $this->expectException(InvalidModel::class);

        new ClassModel(
            ClassName::fromFqcn('App\User'),
            ClassKind::from(ClassKind::OPEN),
            ClassName::fromFqcn('\App\User'),
            [],
            Mutability::from(Mutability::MUTABLE),
            DocModel::none(),
            new SchemaLocation('a.json'),
        );
    }

    public function testOnlyAbstractClassesCarryADiscriminator(): void
    {
        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage('Only an abstract class');

        $this->classWith([], ClassKind::FINAL, new DiscriminatorModel('kind', ['cat' => ClassName::fromFqcn('App\Cat')]));
    }

    public function testAbstractClassKeepsItsDiscriminator(): void
    {
        $discriminator = new DiscriminatorModel('kind', ['cat' => ClassName::fromFqcn('App\Cat'), '1' => ClassName::fromFqcn('App\One')]);
        $class = $this->classWith([], ClassKind::ABSTRACT, $discriminator);

        self::assertSame($discriminator, $class->discriminator());
        self::assertSame('kind', $discriminator->propertyName());
        self::assertSame(['cat', '1'], $discriminator->values());
        self::assertNotNull($discriminator->classFor('1'));
        self::assertSame('App\One', $discriminator->classFor('1')->fqcn());
        self::assertNull($discriminator->classFor('dog'));
    }

    public function testDiscriminatorNeedsAMapping(): void
    {
        $this->expectException(InvalidModel::class);

        new DiscriminatorModel('kind', []);
    }

    public function testWithMethodsReturnValidatedCopies(): void
    {
        $class = $this->classWith([$this->property('id')]);
        $attribute = new AttributeModel(ClassName::fromFqcn('App\Marker'));

        self::assertSame([$attribute], $class->withAttributes($attribute)->attributes());
        self::assertSame([], $class->attributes());
        self::assertCount(2, $class->withProperties($this->property('id'), $this->property('email'))->properties());

        $this->expectException(InvalidModel::class);
        $class->withProperties($this->property('id'), $this->property('id'));
    }

    /**
     * @param list<PropertyModel> $properties
     */
    private function classWith(array $properties, string $kind = ClassKind::FINAL, ?DiscriminatorModel $discriminator = null): ClassModel
    {
        return new ClassModel(
            ClassName::fromFqcn('App\User'),
            ClassKind::from($kind),
            null,
            $properties,
            Mutability::from(Mutability::IMMUTABLE),
            DocModel::none(),
            new SchemaLocation('a.json'),
            [],
            $discriminator,
        );
    }

    private function property(string $name, ?string $wireName = null): PropertyModel
    {
        return new PropertyModel($name, $wireName ?? $name, ScalarType::string(), true, null, DocModel::none(), new SchemaLocation('a.json'));
    }
}
