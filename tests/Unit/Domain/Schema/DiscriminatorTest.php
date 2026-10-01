<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Schema;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Discriminator;
use PHPUnit\Framework\TestCase;

final class DiscriminatorTest extends TestCase
{
    public function testMapsValuesToReferences(): void
    {
        $discriminator = new Discriminator('kind', ['cat' => '#/components/schemas/Cat']);

        self::assertSame('kind', $discriminator->propertyName());
        self::assertSame(['cat'], $discriminator->values());
        self::assertSame('#/components/schemas/Cat', $discriminator->refFor('cat'));
        self::assertNull($discriminator->refFor('dog'));
    }

    public function testNumericValuesStayStrings(): void
    {
        $discriminator = new Discriminator('version', ['1' => '#/components/schemas/V1']);

        self::assertSame(['1'], $discriminator->values());
        self::assertSame('#/components/schemas/V1', $discriminator->refFor('1'));
    }

    public function testRejectsAnEmptyPropertyName(): void
    {
        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage('property name must not be empty');

        new Discriminator('');
    }

    public function testRejectsAnEmptyReference(): void
    {
        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage('maps to an empty reference');

        new Discriminator('kind', ['cat' => '']);
    }

    public function testABareMappingValueNamesAComponentSchema(): void
    {
        $discriminator = new Discriminator('kind', ['cat' => 'Cat', 'dog' => 'dog.yaml#/Dog']);

        self::assertSame('#/components/schemas/Cat', $discriminator->refFor('cat'));
        self::assertSame('dog.yaml#/Dog', $discriminator->refFor('dog'));
    }
}
