<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Target;

use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use MSSTC4PHP\DtoGenerator\Domain\Shared\AbstractEnum;

final class DateTimeClass extends AbstractEnum
{
    public const IMMUTABLE = 'DateTimeImmutable';

    public const MUTABLE = 'DateTime';

    /**
     * @return class-string<DateTimeInterface>
     */
    public function className(): string
    {
        return $this->value() === self::MUTABLE ? DateTime::class : DateTimeImmutable::class;
    }

    protected static function values(): array
    {
        return [self::IMMUTABLE, self::MUTABLE];
    }
}
