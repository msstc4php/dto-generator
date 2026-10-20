<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Builder\OpenApi30;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostic;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use PHPUnit\Framework\TestCase;

final class OpenApi30Test extends TestCase
{
    /**
     * @param array<array-key, mixed> $node
     * @param array<array-key, mixed> $expected
     * @param list<string> $messages
     *
     * @dataProvider nodes
     */
    public function testRewritesANodeAsOpenApi31(array $node, array $expected, array $messages = []): void
    {
        $diagnostics = new Diagnostics();

        self::assertSame($expected, OpenApi30::asOpenApi31($node, new SchemaLocation('a.yaml', '/S'), $diagnostics, false));
        self::assertSame($messages, $this->messages($diagnostics));
    }

    /**
     * @return iterable<string, array{0: array<array-key, mixed>, 1: array<array-key, mixed>, 2?: list<string>}>
     */
    public static function nodes(): iterable
    {
        yield 'nothing to rewrite' => [['type' => 'integer', 'minimum' => 1], ['type' => 'integer', 'minimum' => 1]];
        yield 'nullable' => [['type' => 'string', 'nullable' => true], ['type' => ['string', 'null']]];
        yield 'not nullable' => [['type' => 'string', 'nullable' => false], ['type' => 'string']];
        yield 'an exclusive minimum' => [['type' => 'integer', 'minimum' => 5, 'exclusiveMinimum' => true], ['type' => 'integer', 'exclusiveMinimum' => 5]];
        yield 'an exclusive maximum' => [['maximum' => 2.5, 'exclusiveMaximum' => true, 'minimum' => 0], ['exclusiveMaximum' => 2.5, 'minimum' => 0]];
        yield 'both exclusive' => [
            ['minimum' => 0, 'maximum' => 9, 'exclusiveMinimum' => true, 'exclusiveMaximum' => true],
            ['exclusiveMinimum' => 0, 'exclusiveMaximum' => 9],
        ];
        yield 'inclusive bounds' => [['minimum' => 0, 'maximum' => 9, 'exclusiveMinimum' => false, 'exclusiveMaximum' => false], ['minimum' => 0, 'maximum' => 9]];
        yield 'an exclusive minimum without a minimum' => [
            ['exclusiveMinimum' => true, 'maximum' => 3],
            ['maximum' => 3],
            ['warning a.yaml#/S/exclusiveMinimum: "exclusiveMinimum: true" has no effect without a numeric "minimum".'],
        ];
        yield 'an exclusive maximum beside a string maximum' => [
            ['exclusiveMaximum' => true, 'maximum' => '3'],
            ['maximum' => '3'],
            ['warning a.yaml#/S/exclusiveMaximum: "exclusiveMaximum: true" has no effect without a numeric "maximum".'],
        ];
        yield 'a numeric exclusive minimum' => [
            ['minimum' => 1, 'exclusiveMinimum' => 5],
            ['minimum' => 1, 'exclusiveMinimum' => 5],
            ['warning a.yaml#/S/exclusiveMinimum: "exclusiveMinimum" is a boolean in OpenAPI 3.0; the number is read as in 3.1.'],
        ];
        yield 'a float exclusive maximum' => [
            ['exclusiveMaximum' => 0.5],
            ['exclusiveMaximum' => 0.5],
            ['warning a.yaml#/S/exclusiveMaximum: "exclusiveMaximum" is a boolean in OpenAPI 3.0; the number is read as in 3.1.'],
        ];
        yield 'a malformed exclusive maximum' => [
            ['maximum' => 3, 'exclusiveMaximum' => 'yes'],
            ['maximum' => 3],
            ['error a.yaml#/S/exclusiveMaximum: "exclusiveMaximum" must be a boolean.'],
        ];
        yield 'a malformed nullable' => [
            ['type' => 'string', 'nullable' => 'yes'],
            ['type' => 'string'],
            ['error a.yaml#/S/nullable: "nullable" must be a boolean.'],
        ];
        yield 'a not' => [
            ['type' => 'integer', 'not' => ['minimum' => 3, 'exclusiveMinimum' => true, 'nullable' => true, 'not' => ['maximum' => 1, 'exclusiveMaximum' => 'no']]],
            ['type' => 'integer', 'not' => ['exclusiveMinimum' => 3, 'not' => ['maximum' => 1]]],
            ['error a.yaml#/S/not/not/exclusiveMaximum: "exclusiveMaximum" must be a boolean.'],
        ];
        yield 'a not that is no object' => [['not' => true], ['not' => true]];
    }

    public function testGivesOnlyErrorsForASchemaThatIsReplaced(): void
    {
        $diagnostics = new Diagnostics();
        $node = ['exclusiveMinimum' => true, 'exclusiveMaximum' => 4, 'not' => ['exclusiveMaximum' => true], 'nullable' => 1];

        self::assertSame(
            ['exclusiveMaximum' => 4, 'not' => []],
            OpenApi30::asOpenApi31($node, new SchemaLocation('a.yaml', '/S'), $diagnostics, true),
        );
        self::assertSame(['error a.yaml#/S/nullable: "nullable" must be a boolean.'], $this->messages($diagnostics));
    }

    /**
     * @return list<string>
     */
    private function messages(Diagnostics $diagnostics): array
    {
        return array_map(static fn (Diagnostic $d): string => $d->toString(), $diagnostics->all());
    }
}
