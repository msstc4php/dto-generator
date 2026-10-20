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

    public function testReadsNamesThatAreKeywordsElsewhereAsNames(): void
    {
        $anchors = new Anchors([
            'components' => ['schemas' => [
                'default' => ['$anchor' => 'Default', '$dynamicAnchor' => 'Default', 'properties' => [
                    'example' => ['$anchor' => 'Example'],
                    'x-id' => ['$anchor' => 'Xid'],
                    'properties' => ['properties' => ['enum' => ['$dynamicAnchor' => 'Enum']]],
                ]],
            ]],
            '$defs' => ['examples' => ['$anchor' => 'Examples'], '$anchor' => ['$anchor' => 'Self']],
            'definitions' => ['const' => ['$anchor' => 'Const']],
            'x-defs' => ['default' => ['$anchor' => 'Hidden']],
            'paths' => ['/p' => ['get' => ['responses' => ['default' => ['content' => ['application/json' => ['schema' => ['$anchor' => 'Err']]]]]]]],
            'webhooks' => ['enum' => ['post' => ['requestBody' => ['content' => ['text/plain' => ['schema' => ['$anchor' => 'Hook']]]]]]],
            'dependencies' => ['default' => ['$anchor' => 'Dependency']],
        ]);

        self::assertSame(['/components/schemas/default'], $anchors->pointersOf('Default'));
        self::assertSame(['/components/schemas/default/properties/example'], $anchors->pointersOf('Example'));
        self::assertSame(['/components/schemas/default/properties/x-id'], $anchors->pointersOf('Xid'));
        self::assertSame(['/components/schemas/default/properties/properties/properties/enum'], $anchors->pointersOf('Enum'));
        self::assertSame(['/$defs/examples'], $anchors->pointersOf('Examples'));
        self::assertSame(['/$defs/$anchor'], $anchors->pointersOf('Self'));
        self::assertSame(['/definitions/const'], $anchors->pointersOf('Const'));
        self::assertSame([], $anchors->pointersOf('Hidden'));
        self::assertSame(['/paths/~1p/get/responses/default/content/application~1json/schema'], $anchors->pointersOf('Err'));
        self::assertSame(['/webhooks/enum/post/requestBody/content/text~1plain/schema'], $anchors->pointersOf('Hook'));
        self::assertSame(['/dependencies/default'], $anchors->pointersOf('Dependency'));
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
