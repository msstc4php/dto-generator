<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Shared;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;
use PHPUnit\Framework\TestCase;
use stdClass;

final class JsonTest extends TestCase
{
    public function testRecognisesLists(): void
    {
        self::assertTrue(Json::isList([]));
        self::assertTrue(Json::isList(['a', 'b']));
        self::assertFalse(Json::isList([1 => 'a']));
        self::assertFalse(Json::isList(['a' => 1]));
        self::assertFalse(Json::isList([0 => 'a', 2 => 'b']));
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
