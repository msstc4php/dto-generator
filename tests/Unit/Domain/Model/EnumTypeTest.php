<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Model;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Model\EnumBacking;
use MSSTC4PHP\DtoGenerator\Domain\Model\EnumType;
use PHPUnit\Framework\TestCase;

final class EnumTypeTest extends TestCase
{
    public function testExposesItsParts(): void
    {
        $type = new EnumType(ClassName::fromFqcn('App\Dto\Currency'), EnumBacking::from(EnumBacking::STRING), ['EUR' => 'EUR', 'usd' => 'USD']);

        self::assertSame('App\Dto\Currency', $type->className()->fqcn());
        self::assertSame('App\Dto\Currency', $type->describe());
        self::assertSame('string', $type->backing()->value());
        self::assertSame('USD', $type->caseFor('usd'));
        self::assertNull($type->caseFor('GBP'));
        self::assertSame(['EUR' => 'EUR', 'usd' => 'USD'], $type->cases());
    }

    public function testFindsIntegerCases(): void
    {
        $type = new EnumType(ClassName::fromFqcn('App\Dto\Level'), EnumBacking::from(EnumBacking::INT), [1 => 'VALUE_1']);

        self::assertSame('VALUE_1', $type->caseFor(1));
        self::assertNull($type->caseFor('1'));
    }

    public function testNeedsACase(): void
    {
        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage('Enum App\Dto\Empty_ has no cases.');

        new EnumType(ClassName::fromFqcn('App\Dto\Empty_'), EnumBacking::from(EnumBacking::STRING), []);
    }
}
