<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Schema;

use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use MSSTC4PHP\DtoGenerator\Domain\Schema\VariantOrigin;
use PHPUnit\Framework\TestCase;

final class VariantOriginTest extends TestCase
{
    private const S = '/components/schemas/S';

    /**
     * @dataProvider parts
     */
    public function testTellsWhatEveryVariantShares(string $part, bool $shared, string $file = '/api.yaml'): void
    {
        $origin = (new VariantOrigin(new SchemaLocation('/api.yaml', self::S), 'S'))
            ->withUnion(new SchemaLocation('/api.yaml', self::S . '/allOf/1'))
            ->withUnion(new SchemaLocation('/api.yaml', self::S . '/allOf/3'))
        ;

        self::assertSame('S', $origin->base());
        self::assertSame($shared, $origin->shares(new SchemaLocation($file, $part)));
    }

    /**
     * @return iterable<string, array{0: string, 1: bool, 2?: string}>
     */
    public static function parts(): iterable
    {
        yield 'the schema' => [self::S, true];
        yield 'a variant' => [self::S . '/x-dto-variant/1/0', true];
        yield 'another member' => [self::S . '/allOf/0', true];
        yield 'a member numbered alike' => [self::S . '/allOf/10', true];
        yield 'a property' => [self::S . '/properties/meta', true];
        yield 'the first union' => [self::S . '/allOf/1', false];
        yield 'an inline variant' => [self::S . '/allOf/1/anyOf/0', false];
        yield 'the second union' => [self::S . '/allOf/3/oneOf/2', false];
        yield 'another schema named alike' => [self::S . '2/allOf/0', false];
        yield 'elsewhere' => ['/components/schemas/A', false];
        yield 'another file' => [self::S . '/allOf/0', false, '/other.yaml'];
    }

    public function testTellsAVariantByItsLocation(): void
    {
        self::assertTrue(VariantOrigin::isVariant(new SchemaLocation('/api.yaml', self::S . '/x-dto-variant/0/1')));
        self::assertTrue(VariantOrigin::isVariant(new SchemaLocation('/api.yaml', self::S . '/x-dto-variant/0/1/properties/p')));
        self::assertFalse(VariantOrigin::isVariant(new SchemaLocation('/api.yaml', self::S . '/x-dto-variants/0')));
        self::assertFalse(VariantOrigin::isVariant(new SchemaLocation('/api.yaml', self::S . '/properties/x-dto-variant-of')));
        self::assertFalse(VariantOrigin::isVariant(new SchemaLocation('/api.yaml', self::S . '/properties/my-x-dto-variant/0')));
        self::assertTrue(VariantOrigin::isVariant(new SchemaLocation('/api.yaml', self::S . '/x-dto-variant')));
    }
}
