<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Model;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;

final class NullableType implements TypeModel
{
    private TypeModel $inner;

    public function __construct(TypeModel $inner)
    {
        if ($inner instanceof self || $inner instanceof MixedType) {
            throw new InvalidModel(sprintf('"%s" already admits null and cannot be made nullable.', $inner->describe()));
        }

        $this->inner = $inner;
    }

    public function inner(): TypeModel
    {
        return $this->inner;
    }

    public function describe(): string
    {
        return $this->inner->describe() . '|null';
    }
}
