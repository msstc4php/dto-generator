<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Model;

final class ListType implements TypeModel
{
    private TypeModel $item;

    public function __construct(TypeModel $item)
    {
        $this->item = $item;
    }

    public function item(): TypeModel
    {
        return $this->item;
    }

    public function describe(): string
    {
        return sprintf('list<%s>', $this->item->describe());
    }
}
