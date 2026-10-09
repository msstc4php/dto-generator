<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Model;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\DiscriminatorValues;
use PHPUnit\Framework\TestCase;

final class DiscriminatorValuesTest extends TestCase
{
    public function testExposesTheValuesThatSelectAClass(): void
    {
        $values = new DiscriminatorValues('petType', ['cat', 2]);

        self::assertSame('petType', $values->property());
        self::assertSame(['cat', 2], $values->values());
        self::assertTrue($values->isChecked());
        self::assertFalse((new DiscriminatorValues('petType', ['cat'], false))->isChecked());
        self::assertSame(['cat', 2], $values->ownValues());
        self::assertSame([], $values->subclassValues());
        self::assertTrue($values->areSubclassValuesChecked());
    }

    public function testTellsTheValuesOnlyASubclassMayPassUp(): void
    {
        $values = new DiscriminatorValues('kind', ['bird', 'parrot', 'macaw'], true, ['parrot', 'macaw'], false);

        self::assertSame(['bird', 'parrot', 'macaw'], $values->values());
        self::assertSame(['bird'], $values->ownValues());
        self::assertSame(['parrot', 'macaw'], $values->subclassValues());
        self::assertFalse($values->areSubclassValuesChecked());
    }

    /**
     * @dataProvider unusable
     *
     * @param list<int|string> $values
     * @param list<int|string> $subclassValues
     */
    public function testRejectsAnUnusableShape(string $property, array $values, string $message, array $subclassValues = []): void
    {
        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage($message);

        new DiscriminatorValues($property, $values, true, $subclassValues);
    }

    /**
     * @return array<string, array{0: string, 1: list<int|string>, 2: string, 3?: list<int|string>}>
     */
    public static function unusable(): array
    {
        return [
            'no values' => ['kind', [], 'Discriminator property "kind" has no value that selects the class.'],
            'not a property name' => ['1kind', ['a'], '"1kind" is not a usable PHP property name.'],
            'only subclass values' => ['kind', ['a'], 'Discriminator property "kind" has no value that selects the class.', ['a']],
            'a stray subclass value' => ['kind', ['a'], 'Subclass value "b" of discriminator property "kind" is not one of its values.', ['b']],
        ];
    }
}
