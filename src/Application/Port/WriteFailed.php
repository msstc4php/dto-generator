<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Port;

use RuntimeException;

final class WriteFailed extends RuntimeException
{
    public static function at(string $path, string $action): self
    {
        return new self(sprintf('Cannot %s "%s".', $action, $path));
    }
}
