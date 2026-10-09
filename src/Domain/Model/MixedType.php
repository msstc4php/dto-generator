<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Model;

/**
 * @api
 */
final class MixedType implements TypeModel
{
    public function describe(): string
    {
        return 'mixed';
    }
}
