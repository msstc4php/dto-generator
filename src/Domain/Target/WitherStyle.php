<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Target;

use MSSTC4PHP\DtoGenerator\Domain\Shared\AbstractEnum;

/**
 * How an immutable DTO produces a modified copy (spec §6.2, row "with*()").
 *
 * @api
 */
final class WitherStyle extends AbstractEnum
{
    public const NONE = 'none';

    public const CLONE_ASSIGN = 'clone-assign';

    public const NEW_SELF = 'new-self';

    public const CLONE_WITH = 'clone-with';

    public function isNone(): bool
    {
        return $this->value() === self::NONE;
    }

    protected static function values(): array
    {
        return [self::NONE, self::CLONE_ASSIGN, self::NEW_SELF, self::CLONE_WITH];
    }
}
