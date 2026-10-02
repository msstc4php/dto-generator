<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Port;

use RuntimeException;

final class ProjectPackagesUnusable extends RuntimeException
{
    private string $lockFile = '';

    private string $reason = '';

    public static function because(string $file, string $reason): self
    {
        $exception = new self(sprintf('%s %s.', $file, $reason));
        $exception->lockFile = $file;
        $exception->reason = $reason;

        return $exception;
    }

    public function file(): string
    {
        return $this->lockFile;
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
