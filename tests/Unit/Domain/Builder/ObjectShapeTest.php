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
        yield 'annotations' => [['example' => ['a' => 'x'], 'externalDocs' => ['url' => 'https://example.com'], 'xml' => ['name' => 'w']] + $wide, self::NARROW, true];
        yield 'a discriminator of the narrow one' => [$wide, ['discriminator' => ['propertyName' => 'a']] + self::NARROW, true];
        yield 'keywords in another order' => [['properties' => ['a' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 9]]] + $wide, $narrowWith(['maxLength' => 9, 'type' => 'string', 'minLength' => 1]), true];
        yield 'another value of a later keyword' => [['properties' => ['a' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 9]]] + $wide, $narrowWith(['type' => 'string', 'minLength' => 2, 'maxLength' => 9]), false];
        yield 'objects in another key order' => [['properties' => ['a' => ['type' => 'string', 'minLength' => 1, 'not' => ['minLength' => 2, 'maxLength' => 3]]]] + $wide, $narrowWith(['not' => ['maxLength' => 3, 'minLength' => 2], 'minLength' => 1, 'type' => 'string']), true];
        yield 'types in another order' => [['properties' => ['a' => ['type' => ['string', 'null']]]] + $wide, $narrowWith(['type' => ['null', 'string']]), true];
        yield 'lists in another order' => [['properties' => ['a' => ['type' => 'string', 'not' => ['enum' => ['x', 'y']]]]] + $wide, $narrowWith(['type' => 'string', 'not' => ['enum' => ['y', 'x']]]), false];
        yield 'an enum in another order' => [['properties' => ['a' => ['type' => 'string', 'enum' => ['x', 'y']]]] + $wide, $narrowWith(['type' => 'string', 'enum' => ['y', 'x']]), false];
        yield 'a list or an object of its keys' => [['properties' => ['a' => ['type' => 'string', 'not' => ['const' => ['x', 'y']]]]] + $wide, $narrowWith(['type' => 'string', 'not' => ['const' => [1 => 'y', 0 => 'x']]]), false];
        yield 'mixed keys in another order' => [['properties' => ['a' => ['type' => 'string', 'not' => ['properties' => ['0' => [], 'abc' => []]]]]] + $wide, $narrowWith(['type' => 'string', 'not' => ['properties' => ['abc' => [], '0' => []]]]), true];
        yield 'an array default in another key order' => [['properties' => ['a' => ['type' => 'array', 'default' => [['p' => 1, 'q' => 2]]]]] + $wide, $narrowWith(['type' => 'array', 'default' => [['q' => 2, 'p' => 1]]]), true];
        yield 'enum objects in another key order' => [['properties' => ['a' => ['enum' => [['p' => 1, 'q' => 2], 'x']]]] + $wide, $narrowWith(['enum' => [['q' => 2, 'p' => 1], 'x']]), true];
        yield 'a nullable wide one' => [['type' => ['object', 'null']] + $wide, self::NARROW, true];
        yield 'an untyped narrow one' => [$wide, array_diff_key(self::NARROW, ['type' => true]), false];
        yield 'both untyped' => [['properties' => $wide['properties']], array_diff_key(self::NARROW, ['type' => true]), true];
        yield 'both nullable' => [['type' => ['object', 'null']] + $wide, ['type' => ['object', 'null']] + self::NARROW, true];
        yield 'a nullable narrow one' => [$wide, ['type' => ['object', 'null']] + self::NARROW, false];
        yield 'readOnly' => [['readOnly' => true] + $wide, self::NARROW, false];
        yield 'not' => [['not' => ['required' => ['b']]] + $wide, self::NARROW, false];
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
