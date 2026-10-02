<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Port;

use RuntimeException;

final class WriteFailed extends RuntimeException
{
    private string $path;

    private function __construct(string $message, string $path)
    {
        parent::__construct($message);
        $this->path = $path;
    }

    public static function at(string $path, string $action): self
    {
        return new self(sprintf('Cannot %s "%s".', $action, $path), $path);
    }

    public function path(): string
    {
        return $this->path;
    }
}
