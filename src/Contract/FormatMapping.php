<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Contract;

use MSSTC4PHP\DtoGenerator\Domain\Model\TypeModel;

/**
 * The PHP type of a string `format`; a refined scalar carries its PHPDoc type. Constraints belong to enrichers.
 */
final class FormatMapping
{
    private TypeModel $type;

    public function __construct(TypeModel $type)
    {
        $this->type = $type;
    }

    public function type(): TypeModel
    {
        return $this->type;
    }
}
