<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Port;

use RuntimeException;

final class DocumentLoadFailed extends RuntimeException
{
    private string $path = '';

    public function path(): string
    {
        return $this->path;
    }

    private static function at(string $path, string $message): self
    {
        $exception = new self($message);
        $exception->path = $path;

        return $exception;
    }

    public static function notFound(string $path): self
    {
        return self::at($path, sprintf('File "%s" does not exist.', $path));
    }

    public static function unreadable(string $path): self
    {
        return self::at($path, sprintf('File "%s" cannot be read.', $path));
    }

    public static function unsupportedFormat(string $path): self
    {
        return self::at($path, sprintf('File "%s" must be YAML (.yaml, .yml) or JSON (.json).', $path));
    }

    public static function malformed(string $path, string $reason): self
    {
        return self::at($path, sprintf('File "%s" is not valid: %s', $path, $reason));
    }

    /**
     * @param string $problem what is wrong, phrased to follow the document's URL
     */
    public static function remote(string $url, string $problem): self
    {
        return self::at($url, sprintf('Remote document "%s" %s', $url, $problem));
    }

    public static function notAnObject(string $path): self
    {
        return self::at($path, sprintf('File "%s" must contain an object at the top level.', $path));
    }
}
