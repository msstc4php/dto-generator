<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Port;

use MSSTC4PHP\DtoGenerator\Domain\Shared\AbstractEnum;

/**
 * Which remote documents a run may fetch (spec F2 §2, F3 §4): none (checks, dry runs, builds without the network),
 * those the cache lacks (a run that writes) or all it reads (`--refresh-remote`).
 */
final class RemotePolicy extends AbstractEnum
{
    public const CACHED = 'cached';

    public const MISSING = 'missing';

    public const ALL = 'all';

    protected static function values(): array
    {
        return [self::CACHED, self::MISSING, self::ALL];
    }
}
