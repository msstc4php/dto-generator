<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Schema;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;
use MSSTC4PHP\DtoGenerator\Domain\Schema\JsonPointer;
use PHPUnit\Framework\TestCase;

final class JsonPointerTest extends TestCase
{
    private const DOCUMENT = [
        'components' => ['schemas' => ['User' => ['type' => 'object'], 'a/b' => ['type' => 'string']]],
        'list' => ['x', 'y'],
        'nothing' => null,
    ];

    public function testSplitsAndDecodesSegments(): void
    {
        self::assertSame([], JsonPointer::segments(''));
        self::assertSame(['a/b', 'c~d', ''], JsonPointer::segments('/a~1b/c~0d/'));
    }

    public function testEncodesSegments(): void
    {
        self::assertSame('/components/schemas/a~1b/x~0y', JsonPointer::fromSegments('components', 'schemas', 'a/b', 'x~y'));
        self::assertSame('', JsonPointer::fromSegments());
    }

    /**
     * @dataProvider invalidPointers
     */
    public function testRejectsInvalidPointers(string $pointer): void
    {
        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage('is not a valid JSON pointer');

        JsonPointer::segments($pointer);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidPointers(): array
    {
        return [
            'no leading slash' => ['components'],
            'bad escape' => ['/a~2'],
            'trailing tilde' => ['/a~'],
        ];
    }

    public function testNavigatesMapsAndLists(): void
    {
        self::assertSame(['type' => 'object'], JsonPointer::get(self::DOCUMENT, '/components/schemas/User'));
        self::assertSame(['type' => 'string'], JsonPointer::get(self::DOCUMENT, '/components/schemas/a~1b'));
        self::assertSame('y', JsonPointer::get(self::DOCUMENT, '/list/1'));
        self::assertSame(self::DOCUMENT, JsonPointer::get(self::DOCUMENT, ''));
    }

    public function testDistinguishesAFoundNullFromAMissingValue(): void
    {
        self::assertTrue(JsonPointer::has(self::DOCUMENT, '/nothing'));
        self::assertNull(JsonPointer::get(self::DOCUMENT, '/nothing'));
        self::assertFalse(JsonPointer::has(self::DOCUMENT, '/missing'));
        self::assertFalse(JsonPointer::has(self::DOCUMENT, '/list/5'));
        self::assertFalse(JsonPointer::has(self::DOCUMENT, '/nothing/deeper'));
    }

    public function testGetRejectsAMissingValue(): void
    {
        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage('JSON pointer "/missing" does not resolve');

        JsonPointer::get(self::DOCUMENT, '/missing');
    }
}
