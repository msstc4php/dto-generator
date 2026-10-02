<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Service\Generate;

use MSSTC4PHP\DtoGenerator\Domain\Shared\AbstractEnum;

/**
 * Write the output, only compare it with the disk (`--check`), or only report the plan (`--dry-run`).
 */
final class Mode extends AbstractEnum
{
    public const WRITE = 'write';

    public const CHECK = 'check';

    public const DRY_RUN = 'dry-run';

    protected static function values(): array
    {
        return [self::WRITE, self::CHECK, self::DRY_RUN];
    }
}
