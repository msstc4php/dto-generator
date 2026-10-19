<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Builder\UnionDistribution;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use PHPUnit\Framework\TestCase;

final class UnionDistributionTest extends TestCase
{
    private const S = '/components/schemas/S';

    /**
     * @dataProvider parts
     */
    public function testTellsTheVariantsPartFromWhatTheSchemaShares(string $variant, string $part, bool $shared, string $file = '/api.yaml'): void
    {
        self::assertSame($shared, UnionDistribution::isShared(new SchemaLocation('/api.yaml', $variant), new SchemaLocation($file, $part)));
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: bool, 3?: string}>
     */
    public static function parts(): iterable
    {
        $variant = self::S . '/x-dto-variant/1/0';

        yield 'the variant itself' => [$variant, $variant, true];
        yield 'the schema' => [$variant, self::S, true];
        yield 'another member' => [$variant, self::S . '/allOf/0', true];
        yield 'a member numbered alike' => [$variant, self::S . '/allOf/10', true];
        yield 'a property' => [$variant, self::S . '/properties/meta', true];
        yield 'the union' => [$variant, self::S . '/allOf/1', false];
        yield 'an inline variant' => [$variant, self::S . '/allOf/1/anyOf/0', false];
        yield 'another schema named alike' => [$variant, self::S . '2/allOf/0', false];
        yield 'elsewhere' => [$variant, '/components/schemas/A', false];
        yield 'another file' => [$variant, self::S . '/allOf/0', false, '/other.yaml'];
        yield 'no variant' => [self::S, self::S . '/allOf/0', false];
        yield 'a variant inside' => [self::S . '/x-dto-variant/1/0/properties/p', self::S . '/allOf/0', false];
        yield 'a variant without its member' => [self::S . '/x-dto-variant/0', self::S . '/allOf/0', false];
    }
}
