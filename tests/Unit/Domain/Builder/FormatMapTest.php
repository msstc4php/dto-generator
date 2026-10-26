<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Builder\FormatMap;
use MSSTC4PHP\DtoGenerator\Domain\Builder\SchemaParser;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassType;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use PHPUnit\Framework\TestCase;

final class FormatMapTest extends TestCase
{
    public function testFindsAFormatByItsSpellingThenByItsName(): void
    {
        $spelled = new ClassType(ClassName::fromFqcn('App\Spelled'));
        $named = new ClassType(ClassName::fromFqcn('App\Named'));
        $map = new FormatMap(['dateTime' => $spelled, 'date-time' => $named, 'Money_Value' => $named]);

        self::assertSame($spelled, $map->typeOf($this->schema('dateTime')));
        self::assertSame($named, $map->typeOf($this->schema('ISO 8601 date-time')));
        self::assertSame($named, $map->typeOf($this->schema('money value')));
        self::assertNull($map->typeOf($this->schema('uuid')));
        self::assertNull($map->typeOf($this->schema(null)));
    }

    public function testTheLaterOfTwoFormatsOfOneNameWins(): void
    {
        $first = new ClassType(ClassName::fromFqcn('App\First'));
        $later = new ClassType(ClassName::fromFqcn('App\Later'));
        $map = new FormatMap(['date-time' => $first, 'datetime' => $later]);

        self::assertSame($later, $map->typeOf($this->schema('DateTime')));
    }

    public function testKnowsAFormatForItsScalarOnly(): void
    {
        $map = new FormatMap([], ['ssh-key' => 'string', 'Odd' => 'int']);

        self::assertTrue($map->knows($this->schema('SSH key'), 'string'));
        self::assertFalse($map->knows($this->schema('ssh-key'), 'int'));
        self::assertTrue($map->knows($this->schema('Odd'), 'int'));
        self::assertTrue($map->knows($this->schema('odd'), 'int'));
        self::assertFalse($map->knows($this->schema(null), 'string'));
    }

    private function schema(?string $format): Schema
    {
        return (new SchemaParser())->parse($format === null ? [] : ['format' => $format], new SchemaLocation('s.yaml', '/S'), new Diagnostics());
    }
}
