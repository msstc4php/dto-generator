<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Model;

final class MapType implements TypeModel
{
    private TypeModel $value;

    public function __construct(TypeModel $value)
    {
        $this->value = $value;
    }

    public function value(): TypeModel
    {
        return $this->value;
    }

    public function describe(): string
    {
        // JSON object keys that look like integers become int keys in PHP arrays.
        return sprintf('array<array-key, %s>', $this->value->describe());
    }
}
