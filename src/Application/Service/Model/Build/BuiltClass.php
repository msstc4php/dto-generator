<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Service\Model\Build;

use MSSTC4PHP\DtoGenerator\Domain\Model\ClassModel;

/**
 * A class together with the config source whose outputDir it belongs to.
 */
final class BuiltClass
{
    private ClassModel $model;

    private int $source;

    public function __construct(ClassModel $model, int $source)
    {
        $this->model = $model;
        $this->source = $source;
    }

    public function model(): ClassModel
    {
        return $this->model;
    }

    public function source(): int
    {
        return $this->source;
    }
}
