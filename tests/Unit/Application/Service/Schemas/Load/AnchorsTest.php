<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Application\Service\Schemas\Load;

use MSSTC4PHP\DtoGenerator\Application\Service\Schemas\Load\Anchors;
use PHPUnit\Framework\TestCase;

final class AnchorsTest extends TestCase
{
    public function testFindsTheSchemasDeclaringAnAnchor(): void
    {
        $anchors = new Anchors([
            '$anchor' => 'Root',
            'components' => ['schemas' => [
                'A' => ['$anchor' => 'A', 'allOf' => [['$anchor' => 'Member'], ['properties' => ['p/q' => ['$anchor' => 'A']]]]],
                'B' => ['$anchor' => 5, 'default' => ['$anchor' => 'Data'], 'enum' => [['$anchor' => 'Data']], 'const' => ['$anchor' => 'Data']],
                'C' => ['example' => ['$anchor' => 'Data'], 'examples' => [['$anchor' => 'Data']], 'x-meta' => ['$anchor' => 'Data']],
            ]],
        ]);

        self::assertSame([''], $anchors->pointersOf('Root'));
        self::assertSame(['/components/schemas/A', '/components/schemas/A/allOf/1/properties/p~1q'], $anchors->pointersOf('A'));
        self::assertSame(['/components/schemas/A/allOf/0'], $anchors->pointersOf('Member'));
        self::assertSame([], $anchors->pointersOf('Data'));
        self::assertSame([], $anchors->pointersOf('5'));
    }

    /**
     * @dataProvider names
     */
    public function testTellsAnAnchorName(string $fragment, bool $name): void
    {
        self::assertSame($name, Anchors::isName($fragment));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function names(): iterable
    {
        yield 'a letter' => ['Pet', true];
        yield 'an underscore first' => ['_pet', true];
        yield 'punctuation after' => ['pet-1.v_2', true];
        yield 'a digit first' => ['1pet', false];
        yield 'a dash first' => ['-pet', false];
        yield 'a space' => ['my pet', false];
        yield 'a trailing newline' => ["pet\n", false];
        yield 'empty' => ['', false];
    }
}
