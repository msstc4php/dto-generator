<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Service\Config\Load;

use MSSTC4PHP\DtoGenerator\Application\Config\GeneratorConfig;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Target\TargetProfile;

final class Output
{
    private ?GeneratorConfig $config;

    private ?TargetProfile $target;

    private Diagnostics $diagnostics;

    public function __construct(?GeneratorConfig $config, ?TargetProfile $target, Diagnostics $diagnostics)
    {
        $this->config = $config;
        $this->target = $target;
        $this->diagnostics = $diagnostics;
    }

    public function config(): ?GeneratorConfig
    {
        return $this->config;
    }

    public function target(): ?TargetProfile
    {
        return $this->target;
    }

    public function diagnostics(): Diagnostics
    {
        return $this->diagnostics;
    }
}
