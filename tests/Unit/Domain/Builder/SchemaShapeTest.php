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

    /**
     * @param array<array-key, mixed> $node
     *
     * @dataProvider plainObjects
     */
    public function testTellsASchemaThatOnlySaysObject(array $node, bool $plain): void
    {
        self::assertSame($plain, SchemaShape::isPlainObject((new SchemaParser())->parse($node, new SchemaLocation('a.yaml', '/A'), new Diagnostics())));
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>, bool}>
     */
    public static function plainObjects(): iterable
    {
        $object = ['type' => 'object'];

        yield 'object' => [$object, true];
        yield 'nullable object with annotations' => [['type' => ['object', 'null'], 'title' => 'T', 'description' => 'D', 'minProperties' => 1], true];
        yield 'empty properties, other properties allowed' => [$object + ['properties' => [], 'additionalProperties' => true], true];
        yield 'no type' => [[], false];
        yield 'object or array' => [['type' => ['object', 'array']], false];
        yield 'a $ref beside' => [$object + ['$ref' => '#/B'], false];
        yield 'x-php-type' => [$object + ['x-php-type' => 'Foo'], false];
        yield 'properties' => [$object + ['properties' => ['a' => []]], false];
        yield 'required' => [$object + ['required' => ['a']], false];
        yield 'allOf' => [$object + ['allOf' => [['type' => 'object']]], false];
        yield 'oneOf' => [$object + ['oneOf' => [['type' => 'object']]], false];
        yield 'enum' => [$object + ['enum' => [['a' => 1]]], false];
        yield 'const' => [$object + ['const' => ['a' => 1]], false];
        yield 'patternProperties' => [$object + ['patternProperties' => ['^a' => ['type' => 'string']]], false];
        yield 'discriminator' => [$object + ['discriminator' => ['propertyName' => 'kind']], false];
        yield 'no other properties' => [$object + ['additionalProperties' => false], false];
        yield 'a schema for other properties' => [$object + ['additionalProperties' => ['type' => 'string']], false];
    }
}
