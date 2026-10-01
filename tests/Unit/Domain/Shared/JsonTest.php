<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Shared;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;
use PHPUnit\Framework\TestCase;
use stdClass;

final class JsonTest extends TestCase
{
    /**
     * @dataProvider arrays
     *
     * @param array<array-key, mixed> $array
     */
    public function testRecognisesLists(array $array, bool $isList): void
    {
        self::assertSame($isList, Json::isList($array));
    }

    /**
     * @return array<string, array{array<array-key, mixed>, bool}>
     */
    public static function arrays(): array
    {
        return [
            'empty' => [[], true],
            'list' => [['a', 'b'], true],
            'starts at one' => [[1 => 'a'], false],
            'map' => [['a' => 1], false],
            'gap' => [[0 => 'a', 2 => 'b'], false],
        ];
    }

    public function testPassesDecodedValuesThrough(): void
    {
        self::assertNull(Json::value(null));
        self::assertSame('s', Json::value('s'));
        self::assertSame(1.5, Json::value(1.5));
        self::assertSame(['a' => [1]], Json::value(['a' => [1]]));
    }

    public function testRejectsObjects(): void
    {
        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage('stdClass is not a JSON value');

        Json::value(new stdClass());
    }
}
