<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Schema;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;
use MSSTC4PHP\DtoGenerator\Domain\Schema\ResolvedSchema;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaBuilder;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaGraph;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use PHPUnit\Framework\TestCase;

final class SchemaGraphTest extends TestCase
{
    public function testFindsSchemasByLocationAndReference(): void
    {
        $user = $this->resolved('/api/openapi.yaml', '/components/schemas/User', 'User', 0);
        $money = $this->resolved('/api/common.json', '/Money', 'Money', null);
        $graph = new SchemaGraph([$user, $money]);

        self::assertSame([$user, $money], $graph->all());
        self::assertSame($user, $graph->get(new SchemaLocation('/api/openapi.yaml', '/components/schemas/User')));
        self::assertSame($money, $graph->resolve('common.json#/Money', $user->location()));
        self::assertSame($user, $graph->resolve('#/components/schemas/User', $user->location()));
        self::assertNull($graph->resolve('#/components/schemas/Missing', $user->location()));
        self::assertNull($graph->resolve('https://example.com/x.json', $user->location()));
    }

    public function testExposesResolvedSchemaParts(): void
    {
        $money = $this->resolved('/api/common.json', '/Money', 'Money', null);
        $owned = $money->withSource(2);

        self::assertNull($money->source());
        self::assertSame(2, $owned->source());
        self::assertSame('Money', $owned->name());
        self::assertFalse($owned->isSelected());
        self::assertSame($money->schema(), $owned->schema());
    }

    public function testRejectsDuplicates(): void
    {
        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage('is registered twice');

        $user = $this->resolved('/api/openapi.yaml', '/components/schemas/User', 'User', 0);
        new SchemaGraph([$user, $user]);
    }

    public function testRejectsAnEmptyName(): void
    {
        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage('needs a name');

        $this->resolved('/api/openapi.yaml', '', '', 0);
    }

    private function resolved(string $file, string $pointer, string $name, ?int $source): ResolvedSchema
    {
        return new ResolvedSchema((new SchemaBuilder(new SchemaLocation($file, $pointer)))->build(), $source, $name, $source !== null);
    }
}
