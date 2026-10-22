<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Model;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Model\DiscriminatorModel;
use PHPUnit\Framework\TestCase;

final class DiscriminatorModelTest extends TestCase
{
    public function testMapsValuesToClasses(): void
    {
        $cat = ClassName::fromFqcn('App\\Cat');
        $discriminator = new DiscriminatorModel('kind', ['cat' => $cat, '1' => ClassName::fromFqcn('App\\One')]);

        self::assertSame('kind', $discriminator->propertyName());
        self::assertSame(['cat', '1'], $discriminator->values());
        self::assertSame($cat, $discriminator->classFor('cat'));
        self::assertNull($discriminator->classFor('dog'));
        self::assertSame(['cat' => $cat, 1 => $discriminator->classFor('1')], $discriminator->mapping());
    }

    public function testHasADefaultClassAndItsFirstValue(): void
    {
        $cat = ClassName::fromFqcn('App\\Cat');
        $other = ClassName::fromFqcn('App\\Other');
        $mapping = ['cat' => $cat, '1' => $other, 'other' => ClassName::fromFqcn('App\\Other')];

        self::assertSame($other, (new DiscriminatorModel('kind', $mapping, $other))->defaultClass());
        self::assertSame('1', (new DiscriminatorModel('kind', $mapping, $other))->defaultValue());
        self::assertNull((new DiscriminatorModel('kind', $mapping))->defaultClass());
        self::assertNull((new DiscriminatorModel('kind', $mapping))->defaultValue());
        self::assertNull((new DiscriminatorModel('kind', ['cat' => $cat], $other))->defaultValue());
    }

    public function testNeedsAMapping(): void
    {
        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage('Discriminator "kind" has no mapping');

        new DiscriminatorModel('kind', []);
    }

    public function testNeedsAPropertyName(): void
    {
        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage('property name must not be empty');

        new DiscriminatorModel('', ['cat' => ClassName::fromFqcn('App\\Cat')]);
    }
}
