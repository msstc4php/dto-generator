<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Target;

use MSSTC4PHP\DtoGenerator\Domain\Shared\AbstractEnum;

final class Mutability extends AbstractEnum
{
    public const IMMUTABLE = 'immutable';

    public const MUTABLE = 'mutable';

    public function isImmutable(): bool
    {
        return $this->value() === self::IMMUTABLE;
    }

    protected static function values(): array
    {
        return [self::IMMUTABLE, self::MUTABLE];
    }
}
