<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Target;

use MSSTC4PHP\DtoGenerator\Domain\Shared\AbstractEnum;

/**
 * One model per schema, or a read and a write model for the schemas readOnly/writeOnly concern (spec F1 §2).
 *
 * @api
 */
final class ReadWriteModels extends AbstractEnum
{
    public const SINGLE = 'single';

    public const SPLIT = 'split';

    protected static function values(): array
    {
        return [self::SINGLE, self::SPLIT];
    }

    public function isSplit(): bool
    {
        return $this->value() === self::SPLIT;
    }
}
