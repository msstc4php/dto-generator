<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Shared\AbstractEnum;

/**
 * Which way a model carries data: read (responses, without writeOnly properties) or write (requests, without readOnly
 * properties).
 */
final class Direction extends AbstractEnum
{
    public const READ = 'read';

    public const WRITE = 'write';

    protected static function values(): array
    {
        return [self::READ, self::WRITE];
    }
}
