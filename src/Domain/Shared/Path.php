<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Shared;

/**
 * Lexical path handling; never touches the filesystem, so symlinks are not resolved.
 */
final class Path
{
    private const UNC_ROOT = '#^//[^/]+(?:/(?!\\.\\.?(?:/|\\z))[^/]+)?#';

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
        return self::normalize(self::isAbsolute($path) ? $path : rtrim($baseDir, '/\\') . '/' . $path);
    }

    public static function normalize(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        // A drive letter is case-insensitive, so one spelling is kept.
        if (preg_match('#^[a-z]:#', $path) === 1) {
            $path = ucfirst($path);
        }

        // "//server/share" is a UNC root: kept as is and never climbed above.
        if (preg_match(self::UNC_ROOT, $path, $matches) === 1) {
            $segments = self::segments((string) substr($path, strlen($matches[0])), true);

            return $segments === [] ? $matches[0] : $matches[0] . '/' . implode('/', $segments);
        }

        $prefix = preg_match('#^(?:[A-Za-z]:)?/#', $path, $matches) === 1 ? $matches[0] : '';

        return $prefix . implode('/', self::segments((string) substr($path, strlen($prefix)), $prefix !== ''));
    }

    public static function directory(string $path): string
    {
        $normalized = self::normalize($path);
        if (preg_match(self::UNC_ROOT . 'D', $normalized, $matches) === 1 && $matches[0] === $normalized) {
            return $normalized;
        }
        $position = strrpos($normalized, '/');
        if ($position === false) {
            return '.';
        }

        $directory = (string) substr($normalized, 0, $position);

        return $directory === '' || preg_match('#^[A-Za-z]:\z#', $directory) === 1 ? $directory . '/' : $directory;
    }

    /**
     * @return list<string> the segments with "." dropped and ".." applied; a rooted path cannot climb above its root
     */
    private static function segments(string $path, bool $rooted): array
    {
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if ($segments !== [] && $segments[count($segments) - 1] !== '..') {
                    array_pop($segments);

                    continue;
                }

                if ($rooted) {
                    continue;
                }
            }

            $segments[] = $segment;
        }

        return $segments;
    }
}
