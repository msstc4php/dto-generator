<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Diagnostic;

use MSSTC4PHP\DtoGenerator\Domain\Shared\AbstractEnum;

final class Severity extends AbstractEnum
{
    public const ERROR = 'error';

    public const WARNING = 'warning';

    public function isError(): bool
    {
        return $this->value() === self::ERROR;
    }

    protected static function values(): array
    {
        return [self::ERROR, self::WARNING];
    }
}
