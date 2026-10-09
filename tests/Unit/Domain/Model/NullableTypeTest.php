<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Model;

use MSSTC4PHP\DtoGenerator\Domain\Model\MixedType;
use MSSTC4PHP\DtoGenerator\Domain\Model\NullableType;
use MSSTC4PHP\DtoGenerator\Domain\Model\ScalarType;
use PHPUnit\Framework\TestCase;

final class NullableTypeTest extends TestCase
{
    public function testNeverWrapsMixedOrNullable(): void
    {
        $nullable = new NullableType(ScalarType::string());

        self::assertInstanceOf(MixedType::class, NullableType::of(new MixedType()));
        self::assertSame($nullable, NullableType::of($nullable));
        self::assertSame('int|null', NullableType::of(ScalarType::int())->describe());
    }
}
