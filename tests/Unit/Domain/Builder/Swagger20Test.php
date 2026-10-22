<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Builder\Swagger20;
use PHPUnit\Framework\TestCase;

final class Swagger20Test extends TestCase
{
    /**
     * @dataProvider nodes
     *
     * @param array<string, mixed> $node
     * @param array<string, mixed> $expected
     */
    public function testRewritesANodeAsOpenApi30WritesIt(array $node, array $expected): void
    {
        self::assertSame($expected, Swagger20::asOpenApi30($node));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, array<string, mixed>}>
     */
    public static function nodes(): iterable
    {
        yield 'a discriminator' => [['discriminator' => 'kind'], ['discriminator' => ['propertyName' => 'kind']]];
        yield 'a 3.0 discriminator' => [['discriminator' => ['propertyName' => 'kind']], ['discriminator' => ['propertyName' => 'kind']]];
        yield 'x-nullable' => [['type' => 'string', 'x-nullable' => true], ['type' => 'string', 'x-nullable' => true, 'nullable' => true]];
        yield 'x-nullable beside nullable' => [['nullable' => false, 'x-nullable' => true], ['nullable' => false, 'x-nullable' => true]];
        yield 'x-nullable false' => [['x-nullable' => false], ['x-nullable' => false]];
        yield 'a file' => [['type' => 'file'], ['type' => 'string', 'format' => 'binary']];
        yield 'a file of a format' => [['type' => 'file', 'format' => 'byte'], ['type' => 'string', 'format' => 'byte']];
        yield 'nothing to rewrite' => [['type' => 'string'], ['type' => 'string']];
    }
}
