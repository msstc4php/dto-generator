<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Model;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\AttributeModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassKind;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Model\DiscriminatorModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\DiscriminatorValues;
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

    public function testRejectsPropertyNamesDifferingOnlyByCase(): void
    {
        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage('declares property "Foo" twice');

        $this->classWith([$this->property('foo'), $this->property('Foo', 'foo_upper')]);
    }

    public function testRejectsExtendingItselfInAnotherCase(): void
    {
        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage('cannot extend itself');

        new ClassModel(
            ClassName::fromFqcn('App\\User'),
            ClassKind::from(ClassKind::OPEN),
            ClassName::fromFqcn('app\\user'),
            [],
            Mutability::from(Mutability::MUTABLE),
            DocModel::none(),
            new SchemaLocation('a.json'),
        );
    }

    public function testPropertyLookupIsExact(): void
    {
        self::assertNull($this->classWith([$this->property('id')])->property('ID'));
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
        $this->expectExceptionMessage('cannot extend itself');

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

    public function testWithMethodsReturnCopies(): void
    {
        $class = $this->classWith([$this->property('id')]);
        $attribute = new AttributeModel(ClassName::fromFqcn('App\\Marker'));

        self::assertSame([$attribute], $class->withAddedAttributes($attribute)->attributes());
        self::assertSame([], $class->attributes());
        self::assertCount(2, $class->withProperties($this->property('id'), $this->property('email'))->properties());
    }

    public function testWithPropertiesRevalidates(): void
    {
        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage('declares property "id" twice');

        $this->classWith([])->withProperties($this->property('id'), $this->property('id'));
    }

    public function testWithAddedAttributesAccumulates(): void
    {
        $first = new AttributeModel(ClassName::fromFqcn('App\First'));
        $second = new AttributeModel(ClassName::fromFqcn('App\Second'));

        self::assertSame([$first, $second], $this->classWith([])->withAddedAttributes($first)->withAddedAttributes($second)->attributes());
    }

    public function testOnlyConcreteClassesAreSelectedByDiscriminatorValues(): void
    {
        $values = new DiscriminatorValues('kind', ['user']);
        self::assertSame([$values], $this->classWith([], ClassKind::OPEN)->withDiscriminatorValues($values)->discriminatorValues());

        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage('Only a concrete class is selected by discriminator values; App\User is abstract.');

        $this->classWith([], ClassKind::ABSTRACT)->withDiscriminatorValues($values);
    }

    public function testRejectsTwoValueSetsForOneProperty(): void
    {
        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage('Class App\User has two sets of discriminator values for $kind.');

        $this->classWith([])->withDiscriminatorValues(new DiscriminatorValues('kind', ['a']), new DiscriminatorValues('kind', ['b']));
    }

    public function testKnowsThePropertiesItsLineageDiscriminatesBy(): void
    {
        $base = $this->classWith([], ClassKind::ABSTRACT, new DiscriminatorModel('sub', ['one' => ClassName::fromFqcn('App\One')]));
        $class = $base->withDiscriminatedProperties('kind', 'sub');

        self::assertSame([], $this->classWith([])->discriminatedProperties());
        self::assertSame(['sub'], $base->discriminatedProperties());
        self::assertSame(['sub', 'kind'], $class->discriminatedProperties());
        self::assertSame(['sub', 'kind'], $class->withProperties($this->property('name'))->discriminatedProperties());
        self::assertSame(['sub', 'kind'], $class->withAddedAttributes(new AttributeModel(ClassName::fromFqcn('App\Marker')))->discriminatedProperties());
        self::assertSame(['kind', 'sub'], $class->withHierarchy(ClassKind::from(ClassKind::FINAL), null, null)->discriminatedProperties());
        self::assertSame(['sub', 'kind'], $class->withDiscriminatorValues()->discriminatedProperties());
    }

    public function testCollapsesRepeatedDiscriminatedProperties(): void
    {
        self::assertSame(['kind', 'sub'], $this->classWith([])->withDiscriminatedProperties('kind', 'sub', 'kind')->discriminatedProperties());
    }

    public function testRejectsAnEmptyDiscriminatedProperty(): void
    {
        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage('Class App\User lists an empty discriminated property.');

        $this->classWith([])->withDiscriminatedProperties('kind', '');
    }

    public function testKeepsDiscriminatorValuesThroughEveryCopy(): void
    {
        $values = new DiscriminatorValues('kind', ['cat', 1]);
        $class = $this->classWith([$this->property('id')])->withDiscriminatorValues($values);

        self::assertSame([], $this->classWith([])->discriminatorValues());
        self::assertSame([$values], $class->discriminatorValues());
        self::assertSame($values, $class->discriminatorValuesOf('kind'));
        self::assertNull($class->discriminatorValuesOf('id'));
        self::assertSame([$values], $class->withProperties($this->property('name'))->discriminatorValues());
        self::assertSame([$values], $class->withAddedAttributes(new AttributeModel(ClassName::fromFqcn('App\Marker')))->discriminatorValues());
        self::assertSame([$values], $class->withHierarchy(ClassKind::from(ClassKind::FINAL), ClassName::fromFqcn('App\Pet'), null)->discriminatorValues());
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
