<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Service\Generate;

final class Input
{
    private string $configPath;

    private Mode $mode;

    public function __construct(string $configPath, Mode $mode)
    {
        $this->configPath = $configPath;
        $this->mode = $mode;
    }

    public function configPath(): string
    {
        return $this->configPath;
    }

    public function mode(): Mode
    {
        return $this->mode;
    }
}
