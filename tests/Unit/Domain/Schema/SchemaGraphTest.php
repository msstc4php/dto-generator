<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Schema;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;
use MSSTC4PHP\DtoGenerator\Domain\Schema\ReferenceUse;
use MSSTC4PHP\DtoGenerator\Domain\Schema\ResolvedSchema;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaBuilder;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaGraph;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use MSSTC4PHP\DtoGenerator\Tests\Support\GraphFixture;
use PHPUnit\Framework\TestCase;

final class SchemaGraphTest extends TestCase
{
    public function testFindsSchemasByLocationAndRecordedReference(): void
    {
        $user = $this->resolved('/api/openapi.yaml', '/components/schemas/User', 'User', 0);
        $money = $this->resolved('/api/common.json', '/Money', 'Money', null);
        $use = new ReferenceUse('common.json#/Money', $user->location()->child('properties', 'salary'));
        $graph = new SchemaGraph([$user, $money], [$use->key() => $money->location()->toString()]);

        self::assertSame([$user, $money], $graph->all());
        self::assertSame($user, $graph->get(new SchemaLocation('/api/openapi.yaml', '/components/schemas/User')));
        self::assertSame($money, $graph->resolve($use));
        self::assertNull($graph->resolve(new ReferenceUse('#Anchor', $user->location())));
    }

    public function testRejectsAnEdgeToAnUnknownSchema(): void
    {
        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage('points to an unknown schema');

        $user = $this->resolved('/api/openapi.yaml', '/components/schemas/User', 'User', 0);
        new SchemaGraph([$user], [(new ReferenceUse('#/x', $user->location()))->key() => '/api/openapi.yaml#/x']);
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

    public function testWalksAChainOfReferencesUntilItEndsOrClosesACycle(): void
    {
        $graph = GraphFixture::load([
            'A' => ['$ref' => '#/components/schemas/B'],
            'B' => ['$ref' => '#/components/schemas/C'],
            'C' => ['type' => 'string'],
            'Loop' => ['$ref' => '#/components/schemas/Back'],
            'Back' => ['$ref' => '#/components/schemas/Loop'],
            'Self' => ['$ref' => '#/components/schemas/Self'],
        ]);
        $schemas = [];
        foreach ($graph->all() as $resolved) {
            $schemas[$resolved->name()] = $resolved->schema();
        }

        $names = static function (array $chain): array {
            $pointers = [];
            foreach ($chain as $schema) {
                self::assertInstanceOf(Schema::class, $schema);
                $pointers[] = $schema->location()->pointer();
            }

            return $pointers;
        };
        [$chain, $cycle] = $graph->walk($schemas['Loop']);

        self::assertSame(['/components/schemas/A', '/components/schemas/B', '/components/schemas/C'], $names($graph->chain($schemas['A'])));
        self::assertNull($graph->walk($schemas['A'])[1]);
        self::assertSame(['/components/schemas/Loop', '/components/schemas/Back'], $names($chain));
        self::assertNotNull($cycle);
        self::assertSame('/components/schemas/Loop', $cycle->location()->pointer());
        self::assertSame(['/components/schemas/Self'], $names($graph->chain($schemas['Self'])));
        self::assertSame(['/components/schemas/C'], $names((new SchemaGraph([]))->chain($schemas['C'])));
        self::assertSame(['/components/schemas/A'], $names((new SchemaGraph([]))->chain($schemas['A'])));
    }
}
