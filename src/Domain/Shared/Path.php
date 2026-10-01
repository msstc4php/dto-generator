<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Shared;

/**
 * Lexical path handling; never touches the filesystem, so symlinks are not resolved.
 */
final class Path
{
    private function __construct()
    {
    }

    public static function isAbsolute(string $path): bool
    {
        return strncmp($path, '/', 1) === 0
            || strncmp($path, '\\', 1) === 0
            || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1;
    }

    public static function resolve(string $baseDir, string $path): string
    {
        return self::normalize(self::isAbsolute($path) ? $path : $baseDir . '/' . $path);
    }

    public static function normalize(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $prefix = preg_match('#^(?:[A-Za-z]:)?/#', $path, $matches) === 1 ? $matches[0] : '';

        $segments = [];
        foreach (explode('/', (string) substr($path, strlen($prefix))) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if ($segments !== [] && $segments[count($segments) - 1] !== '..') {
                    array_pop($segments);

                    continue;
                }

                if ($prefix !== '') {
                    continue;
                }
            }

            $segments[] = $segment;
        }

        return $prefix . implode('/', $segments);
    }

    public static function directory(string $path): string
    {
        $normalized = self::normalize($path);
        $position = strrpos($normalized, '/');
        if ($position === false) {
            return '.';
        }

        $directory = (string) substr($normalized, 0, $position);

        return $directory === '' || preg_match('#^[A-Za-z]:\z#', $directory) === 1 ? $directory . '/' : $directory;
    }
}
