<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Target;

use MSSTC4PHP\DtoGenerator\Domain\Shared\AbstractEnum;

/**
 * @api
 */
final class AccessorStyle extends AbstractEnum
{
    public const AUTO = 'auto';

    public const GETTERS = 'getters';

    public const PUBLIC_PROPERTIES = 'public-properties';

    protected static function values(): array
    {
        return [self::AUTO, self::GETTERS, self::PUBLIC_PROPERTIES];
    }

    public function isAuto(): bool
    {
        return $this->value() === self::AUTO;
    }
}
