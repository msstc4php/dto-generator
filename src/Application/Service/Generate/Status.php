<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Service\Generate;

use MSSTC4PHP\DtoGenerator\Domain\Shared\AbstractEnum;

/**
 * The outcome of a run; the CLI maps it to the exit codes of spec §9.2.
 *
 * @api
 */
final class Status extends AbstractEnum
{
    public const OK = 'ok';

    public const OUT_OF_DATE = 'out-of-date';

    public const GENERATION_FAILED = 'generation-failed';

    public const CONFIG_FAILED = 'config-failed';

    protected static function values(): array
    {
        return [self::OK, self::OUT_OF_DATE, self::GENERATION_FAILED, self::CONFIG_FAILED];
    }
}
