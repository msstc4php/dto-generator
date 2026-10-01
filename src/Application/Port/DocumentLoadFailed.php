<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Port;

use RuntimeException;

final class DocumentLoadFailed extends RuntimeException
{
    public static function notFound(string $path): self
    {
        return new self(sprintf('File "%s" does not exist.', $path));
    }

    public static function unreadable(string $path): self
    {
        return new self(sprintf('File "%s" cannot be read.', $path));
    }

    public static function unsupportedFormat(string $path): self
    {
        return new self(sprintf('File "%s" must be YAML (.yaml, .yml) or JSON (.json).', $path));
    }

    public static function malformed(string $path, string $reason): self
    {
        return new self(sprintf('File "%s" is not valid: %s', $path, $reason));
    }

    public static function notAnObject(string $path): self
    {
        return new self(sprintf('File "%s" must contain an object at the top level.', $path));
    }
}
