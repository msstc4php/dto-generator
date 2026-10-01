<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Target;

use MSSTC4PHP\DtoGenerator\Domain\Shared\AbstractEnum;

/**
 * Where an immutable class enforces immutability: nowhere natively (7.4/8.0), on each property (8.1) or on the
 * class (8.2+).
 */
final class ReadonlyMode extends AbstractEnum
{
    public const NONE = 'none';

    public const PROPERTIES = 'properties';

    public const CLASS_ = 'class';

    protected static function values(): array
    {
        return [self::NONE, self::PROPERTIES, self::CLASS_];
    }
}
