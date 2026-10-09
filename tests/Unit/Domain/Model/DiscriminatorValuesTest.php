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
    }

    /**
     * @dataProvider unusable
     *
     * @param list<int|string> $values
     */
    public function testRejectsAnUnusableShape(string $property, array $values, string $message): void
    {
        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage($message);

        new DiscriminatorValues($property, $values);
    }

    /**
     * @return array<string, array{string, list<int|string>, string}>
     */
    public static function unusable(): array
    {
        return [
            'no values' => ['kind', [], 'Discriminator property "kind" has no value that selects the class.'],
            'not a property name' => ['1kind', ['a'], '"1kind" is not a usable PHP property name.'],
        ];
    }
}
