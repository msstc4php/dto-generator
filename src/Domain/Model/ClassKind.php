<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Model;

use MSSTC4PHP\DtoGenerator\Domain\Shared\AbstractEnum;

/**
 * @api
 */
final class ClassKind extends AbstractEnum
{
    public const FINAL = 'final';

    /** Non-final: an `allOf` base that other DTOs extend. */
    public const OPEN = 'open';

    public const ABSTRACT = 'abstract';

    protected static function values(): array
    {
        return [self::FINAL, self::OPEN, self::ABSTRACT];
    }

    public function isAbstract(): bool
    {
        return $this->value() === self::ABSTRACT;
    }
}
