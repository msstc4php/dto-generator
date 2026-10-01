<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Model;

final class ClassType implements TypeModel
{
    private ClassName $className;

    public function __construct(ClassName $className)
    {
        $this->className = $className;
    }

    public function className(): ClassName
    {
        return $this->className;
    }

    public function describe(): string
    {
        return $this->className->fqcn();
    }
}
