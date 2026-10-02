<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Service\Extension\Load;

use MSSTC4PHP\DtoGenerator\Application\Extension\Registry;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;

final class Output
{
    private Registry $registry;

    private Diagnostics $diagnostics;

    public function __construct(Registry $registry, Diagnostics $diagnostics)
    {
        $this->registry = $registry;
        $this->diagnostics = $diagnostics;
    }

    public function registry(): Registry
    {
        return $this->registry;
    }

    public function diagnostics(): Diagnostics
    {
        return $this->diagnostics;
    }
}
