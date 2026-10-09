<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Model;

use MSSTC4PHP\DtoGenerator\Domain\Shared\AbstractEnum;

/**
 * @api
 */
final class EnumBacking extends AbstractEnum
{
    public const STRING = 'string';

    public const INT = 'int';

    protected static function values(): array
    {
        return [self::STRING, self::INT];
    }
}
