<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Builder\ObjectShape;
use MSSTC4PHP\DtoGenerator\Domain\Builder\SchemaParser;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use PHPUnit\Framework\TestCase;

final class ObjectShapeTest extends TestCase
{
    private const NARROW = ['type' => 'object', 'required' => ['a'], 'properties' => ['a' => ['type' => 'string'], 'b' => ['type' => 'integer']]];

    /**
     * @dataProvider shapes
     *
     * @param array<string, mixed> $wide
     * @param array<string, mixed> $narrow
     */
    public function testTellsWhetherTheNarrowSchemaAdmitsOnlyObjectsOfTheWideOne(array $wide, array $narrow, bool $admits, string $narrowFile = 'a.yaml'): void
    {
        self::assertSame($admits, ObjectShape::admits($this->schema($wide, 'a.yaml'), $this->schema($narrow, $narrowFile)));
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: array<string, mixed>, 2: bool, 3?: string}>
     */
    public static function shapes(): iterable
    {
        $wide = ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]];
        $narrowWith = static fn (array $a): array => ['properties' => ['a' => $a, 'b' => ['type' => 'integer']]] + self::NARROW;

        yield 'more properties' => [$wide, self::NARROW, true];
        yield 'other properties allowed' => [['additionalProperties' => true] + $wide, self::NARROW, true];
        yield 'another title' => [['properties' => ['a' => ['type' => 'string', 'title' => 'A']]] + $wide, $narrowWith(['type' => 'string', 'title' => 'B']), true];
        yield 'another file' => [$wide, self::NARROW, false, 'b.yaml'];
        yield 'a default' => [['default' => ['a' => 'x']] + $wide, self::NARROW, false];
        yield 'an enum' => [['enum' => [['a' => 'x']]] + $wide, self::NARROW, false];
        yield 'another type' => [$wide, $narrowWith(['type' => 'integer']), false];
        yield 'another keyword' => [$wide, $narrowWith(['type' => 'string', 'minLength' => 1]), false];
        foreach ([
            'properties' => ['properties' => ['x' => []]],
            'other properties' => ['additionalProperties' => false],
            'an allOf' => ['allOf' => [['type' => 'string']]],
            'a oneOf' => ['oneOf' => [['type' => 'string']]],
            'an anyOf' => ['anyOf' => [['type' => 'string']]],
            'a discriminator' => ['discriminator' => ['propertyName' => 'x']],
        ] as $name => $keyword) {
            $property = ['type' => 'string'] + $keyword;
            yield "a property with {$name}" => [['properties' => ['a' => $property]] + $wide, $narrowWith($property), false];
        }
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function schema(array $raw, string $file): Schema
    {
        return (new SchemaParser())->parse($raw, new SchemaLocation('/p/' . $file, '/components/schemas/S'), new Diagnostics());
    }
}
