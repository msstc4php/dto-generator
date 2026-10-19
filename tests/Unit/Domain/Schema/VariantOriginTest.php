<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Schema;

use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use MSSTC4PHP\DtoGenerator\Domain\Schema\VariantLevel;
use MSSTC4PHP\DtoGenerator\Domain\Schema\VariantOrigin;
use PHPUnit\Framework\TestCase;

final class VariantOriginTest extends TestCase
{
    private const S = '/components/schemas/S';

    /**
     * @dataProvider parts
     *
     * @param list<string>|null $variants
     */
    public function testTellsTheVariantsAPartComesWith(string $part, ?array $variants, string $file = '/api.yaml'): void
    {
        $origin = (new VariantOrigin(new SchemaLocation('/api.yaml', self::S)))
            ->withLevel(new VariantLevel(new SchemaLocation('/api.yaml', self::S . '/allOf/1'), new SchemaLocation('/api.yaml', self::S . '/allOf/1/anyOf/0'), 'A'))
            ->withLevel(new VariantLevel(new SchemaLocation('/api.yaml', self::S . '/allOf/1/anyOf/0'), new SchemaLocation('/api.yaml', self::S . '/allOf/1/anyOf/0/x-dto-variant/0/1'), 'C'))
        ;

        self::assertSame(['A', 'C'], $origin->variants());
        self::assertSame('C', $origin->variant());
        self::assertSame($variants, $origin->variantsOf(new SchemaLocation($file, $part)));
    }

    /**
     * @return iterable<string, array{0: string, 1: list<string>|null, 2?: string}>
     */
    public static function parts(): iterable
    {
        yield 'the schema' => [self::S, []];
        yield 'another member' => [self::S . '/allOf/0', []];
        yield 'a member numbered alike' => [self::S . '/allOf/10/properties/p', []];
        yield 'a property' => [self::S . '/properties/meta', []];
        yield 'a property of the first variant' => [self::S . '/allOf/1/anyOf/0/properties/q', ['A']];
        yield 'a part of the second variant' => [self::S . '/allOf/1/anyOf/0/x-dto-variant/0/1/properties/r', ['A', 'C']];
        yield 'another variant of the first union' => [self::S . '/allOf/1/anyOf/1/properties/q', []];
        yield 'another schema named alike' => [self::S . '2/allOf/0', null];
        yield 'elsewhere' => ['/components/schemas/A', null];
        yield 'another file' => [self::S . '/allOf/0', null, '/other.yaml'];
    }

    public function testHasNoVariantAtTheRoot(): void
    {
        $origin = new VariantOrigin(new SchemaLocation('/api.yaml', self::S));

        self::assertNull($origin->variant());
        self::assertSame([], $origin->variants());
        self::assertSame([], $origin->variantsOf(new SchemaLocation('/api.yaml', self::S . '/allOf/1/anyOf/0')));
    }

    public function testStopsAtTheFirstVariantAPartDoesNotComeWith(): void
    {
        $origin = (new VariantOrigin(new SchemaLocation('/api.yaml', self::S)))
            ->withLevel(new VariantLevel(new SchemaLocation('/api.yaml', self::S . '/allOf/1'), new SchemaLocation('/api.yaml', self::S . '/allOf/1/anyOf/0'), 'A'))
            ->withLevel(new VariantLevel(new SchemaLocation('/api.yaml', self::S . '/allOf/2'), new SchemaLocation('/api.yaml', self::S . '/allOf/2/anyOf/1'), 'C'))
        ;

        self::assertSame([], $origin->variantsOf(new SchemaLocation('/api.yaml', self::S . '/allOf/2/anyOf/1/properties/p')));
    }

    public function testKeepsWhatALevelHolds(): void
    {
        $level = new VariantLevel(new SchemaLocation('/api.yaml', self::S . '/allOf/1'), new SchemaLocation('/api.yaml', self::S . '/allOf/1/anyOf/0'), 'A');

        self::assertSame(self::S . '/allOf/1', $level->union()->pointer());
        self::assertSame(self::S . '/allOf/1/anyOf/0', $level->variant()->pointer());
        self::assertSame('A', $level->name());
    }

    public function testTellsAVariantByItsLocation(): void
    {
        self::assertTrue(VariantOrigin::isVariant(new SchemaLocation('/api.yaml', self::S . '/x-dto-variant/0/1')));
        self::assertTrue(VariantOrigin::isVariant(new SchemaLocation('/api.yaml', self::S . '/x-dto-variant/0/1/properties/p')));
        self::assertTrue(VariantOrigin::isVariant(new SchemaLocation('/api.yaml', self::S . '/x-dto-variant')));
        self::assertFalse(VariantOrigin::isVariant(new SchemaLocation('/api.yaml', self::S . '/x-dto-variants/0')));
        self::assertFalse(VariantOrigin::isVariant(new SchemaLocation('/api.yaml', self::S . '/properties/x-dto-variant-of')));
        self::assertFalse(VariantOrigin::isVariant(new SchemaLocation('/api.yaml', self::S . '/properties/my-x-dto-variant/0')));
    }
}
