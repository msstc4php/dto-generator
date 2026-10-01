<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Shared\Fixture;

use MSSTC4PHP\DtoGenerator\Domain\Shared\AbstractEnum;

final class Colour extends AbstractEnum
{
    public const RED = 'red';

    public const GREEN = 'green';

    protected static function values(): array
    {
        return [self::RED, self::GREEN];
    }
}
