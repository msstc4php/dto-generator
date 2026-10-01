<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Service\Config\Load;

use InvalidArgumentException;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Path;

final class Input
{
    private string $configPath;

    public function __construct(string $configPath)
    {
        if (!Path::isAbsolute($configPath)) {
            throw new InvalidArgumentException(sprintf('Config path "%s" must be absolute; resolve it against the working directory first.', $configPath));
        }

        $this->configPath = Path::normalize($configPath);
    }

    public function configPath(): string
    {
        return $this->configPath;
    }
}
