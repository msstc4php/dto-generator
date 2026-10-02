<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Service\Model\Build;

use MSSTC4PHP\DtoGenerator\Domain\Model\EnumModel;

/**
 * An enum together with the index of the source whose namespace and output directory it belongs to.
 */
final class BuiltEnum
{
    private EnumModel $model;

    private int $source;

    public function __construct(EnumModel $model, int $source)
    {
        $this->model = $model;
        $this->source = $source;
    }

    public function model(): EnumModel
    {
        return $this->model;
    }

    public function source(): int
    {
        return $this->source;
    }
}
