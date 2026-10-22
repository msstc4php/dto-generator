<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Builder\Swagger20;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostic;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
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
        $diagnostics = new Diagnostics();

        self::assertSame($expected, Swagger20::asOpenApi30($node, new SchemaLocation('/a.yaml', '/definitions/A'), $diagnostics, false));
        self::assertSame([], $diagnostics->all());
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

    public function testWarnsAboutAnXNullableThatIsNoBooleanUnlessTheSchemaIsReplaced(): void
    {
        $diagnostics = new Diagnostics();
        $at = new SchemaLocation('/a.yaml', '/definitions/A');

        self::assertSame(['x-nullable' => 'true'], Swagger20::asOpenApi30(['x-nullable' => 'true'], $at, $diagnostics, false));
        self::assertSame(['x-nullable' => 1], Swagger20::asOpenApi30(['x-nullable' => 1], $at, $diagnostics, true));
        self::assertSame(['warning /a.yaml#/definitions/A/x-nullable: "x-nullable" must be a boolean; it has no effect.'], array_map(static fn (Diagnostic $d): string => $d->toString(), $diagnostics->all()));
    }

    public function testMapsTheDiscriminatorValueOfAChildToItInItsParent(): void
    {
        $definitions = [
            ['Pet', ['discriminator' => 'kind']],
            ['Cat', ['allOf' => [['$ref' => '#/definitions/Pet']], 'x-discriminator-value' => 'cat']],
            ['Dog~1', ['allOf' => [['$ref' => '#/definitions/Pet'], ['$ref' => '#/definitions/Missing']], 'x-discriminator-value' => 7]],
            ['Fox', ['allOf' => [['$ref' => '#/definitions/Pet']], 'x-discriminator-value' => 'cat']],
            ['Owl', ['allOf' => [['$ref' => 'other.yaml#/definitions/Pet'], ['$ref' => '#/x/Pet'], ['$ref' => '#/definitions/Pet/x'], ['$ref' => '#/defi~2'], 'not a schema'], 'x-discriminator-value' => 'owl']],
            ['Eel', ['allOf' => [['$ref' => '#/definitions/Plain']], 'x-discriminator-value' => 'eel']],
            ['Plain', ['type' => 'object']],
            ['Ant', ['allOf' => [['$ref' => '#/definitions/Bug']], 'x-discriminator-value' => 'ant']],
            ['Bug', ['discriminator' => ['propertyName' => 'kind', 'mapping' => ['ant' => 'Other']]]],
            ['Bee', ['allOf' => [['$ref' => '#/definitions/Pet']], 'x-discriminator-value' => true]],
            ['Elk', 'not a schema'],
        ];

        $result = Swagger20::withDiscriminatorValues($definitions);

        self::assertSame(['propertyName' => 'kind', 'mapping' => ['cat' => '#/definitions/Cat', '7' => '#/definitions/Dog~01']], $result[0][1]['discriminator'] ?? null);
        self::assertSame(['type' => 'object'], $result[6][1]);
        self::assertSame(['propertyName' => 'kind', 'mapping' => ['ant' => 'Other']], $result[8][1]['discriminator'] ?? null);
        self::assertSame(array_slice($definitions, 1, 7), array_slice($result, 1, 7));
    }
}
