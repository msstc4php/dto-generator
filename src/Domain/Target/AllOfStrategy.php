<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Target;

use MSSTC4PHP\DtoGenerator\Domain\Shared\AbstractEnum;

final class AllOfStrategy extends AbstractEnum
{
    public const EXTENDS = 'extends';

    public const MERGE = 'merge';

    protected static function values(): array
    {
        return [self::EXTENDS, self::MERGE];
    }
}
