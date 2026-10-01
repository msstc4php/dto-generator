<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Shared;

use InvalidArgumentException;
use LogicException;
use MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Shared\Fixture\Colour;
use MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Shared\Fixture\Shade;
use PHPUnit\Framework\TestCase;

final class AbstractEnumTest extends TestCase
{
    public function testFromReturnsTheSameInstanceForTheSameValue(): void
    {
        self::assertSame(Colour::from(Colour::RED), Colour::from(Colour::RED));
    }

    public function testValueRoundTrips(): void
    {
        self::assertSame('green', Colour::from(Colour::GREEN)->value());
    }

    public function testEqualsComparesValues(): void
    {
        self::assertTrue(Colour::from(Colour::RED)->equals(Colour::from(Colour::RED)));
        self::assertFalse(Colour::from(Colour::RED)->equals(Colour::from(Colour::GREEN)));
    }

    public function testFromRejectsAnUnknownValue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"blue" is not a valid');

        Colour::from('blue');
    }

    public function testTryFromReturnsNullForAnUnknownValue(): void
    {
        self::assertNull(Colour::tryFrom('blue'));
    }

    public function testRefusesSerializationBecauseItWouldBreakIdentity(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('cannot be serialized');

        serialize(Colour::from(Colour::RED));
    }

    public function testRefusesUnserialization(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('cannot be unserialized');

        unserialize(sprintf('O:%d:"%s":0:{}', strlen(Colour::class), Colour::class));
    }

    public function testTryFromAndCasesReturnTheInternedInstances(): void
    {
        self::assertSame(Colour::from(Colour::RED), Colour::tryFrom(Colour::RED));
        self::assertSame(Colour::from(Colour::GREEN), Colour::cases()[1]);
    }

    public function testInstancesAreSeparatedPerClass(): void
    {
        $shade = Shade::from(Shade::RED);

        self::assertInstanceOf(Shade::class, $shade);
        self::assertFalse($shade->equals(Colour::from(Colour::RED)));
    }

    public function testCasesListsEveryValueInDeclarationOrder(): void
    {
        self::assertSame(
            ['red', 'green'],
            array_map(static fn (Colour $colour): string => $colour->value(), Colour::cases()),
        );
    }
}
