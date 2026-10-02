<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Port;

use RuntimeException;

final class WriteFailed extends RuntimeException
{
    private string $path = '';

    public static function at(string $path, string $action): self
    {
        $exception = new self(sprintf('Cannot %s "%s".', $action, $path));
        $exception->path = $path;

        return $exception;
    }

    public function path(): string
    {
        return $this->path;
    }
}
