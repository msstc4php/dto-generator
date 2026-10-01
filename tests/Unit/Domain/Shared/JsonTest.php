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

    /**
     * @dataProvider floats
     */
    public function testPrintsFloatsIndependentlyOfIniAndLocale(float $value, string $expected): void
    {
        $precision = ini_get('serialize_precision');
        $locale = setlocale(LC_NUMERIC, '0');
        ini_set('serialize_precision', '17');
        setlocale(LC_NUMERIC, 'de_DE.UTF-8', 'de_DE', 'German');

        try {
            self::assertSame($expected, Json::floatToString($value));
            self::assertSame('17', ini_get('serialize_precision'), 'the setting is restored');
        } finally {
            ini_set('serialize_precision', (string) $precision);
            setlocale(LC_NUMERIC, (string) $locale);
        }
    }

    /**
     * @return array<string, array{float, string}>
     */
    public static function floats(): array
    {
        return [
            'one decimal' => [8.2, '8.2'],
            'zero decimal' => [8.0, '8.0'],
            'two decimals' => [8.05, '8.05'],
            'negative' => [-1.5, '-1.5'],
            'two-digit integer part' => [99.99, '99.99'],
            'three-digit integer part' => [123.4, '123.4'],
            'tiny' => [1e-20, '1.0E-20'],
            'huge' => [1e25, '1.0E+25'],
        ];
    }
}
