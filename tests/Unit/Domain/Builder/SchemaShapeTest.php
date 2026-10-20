<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Builder\SchemaParser;
use MSSTC4PHP\DtoGenerator\Domain\Builder\SchemaShape;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use PHPUnit\Framework\TestCase;

final class SchemaShapeTest extends TestCase
{
    /**
     * @param array<array-key, mixed> $node
     *
     * @dataProvider nulls
     */
    public function testTellsASchemaThatAdmitsOnlyNull(array $node, bool $null): void
    {
        self::assertSame($null, SchemaShape::isNull((new SchemaParser())->parse($node, new SchemaLocation('a.yaml', '/A'), new Diagnostics())));
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>, bool}>
     */
    public static function nulls(): iterable
    {
        yield 'null' => [['type' => 'null'], true];
        yield 'null in a list' => [['type' => ['null']], true];
        yield 'nullable' => [['type' => ['string', 'null']], false];
        yield 'no type' => [[], false];
        yield 'a $ref beside null' => [['$ref' => '#/A', 'type' => ['null']], false];
        yield 'an allOf beside null' => [['allOf' => [['type' => 'string']], 'type' => ['null']], false];
        yield 'a oneOf beside null' => [['oneOf' => [['type' => 'string']], 'type' => ['null']], false];
        yield 'an anyOf beside null' => [['anyOf' => [['type' => 'string']], 'type' => ['null']], false];
    }
}
