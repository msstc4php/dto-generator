<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Shared;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;

/**
 * The http(s) URLs a remote $ref names (spec F2 §3): one spelling per document, and references resolved against the
 * document that holds them (RFC 3986 §5.2).
 *
 * @api
 */
final class Url
{
    private const DEFAULT_PORTS = ['http' => 80, 'https' => 443];

    private function __construct()
    {
    }

    /**
     * Whether the string starts with a URI scheme, which a file path never does (a drive letter has no "//").
     */
    public static function isUrl(string $value): bool
    {
        return preg_match('#^[A-Za-z][A-Za-z0-9+.\-]*://#', $value) === 1;
    }

    /**
     * Scheme and host in lower case, no default port, no dot segments, no fragment.
     *
     * @throws InvalidModel unless it is an absolute http(s) URL without credentials
     */
    public static function normalize(string $url): string
    {
        $scheme = self::isUrl($url) ? strtolower((string) strstr($url, ':', true)) : '';
        if ($scheme !== '' && !isset(self::DEFAULT_PORTS[$scheme])) {
            throw new InvalidModel(sprintf('URL "%s": only http and https are supported.', $url));
        }

        $parts = $scheme === '' ? false : parse_url($url);
        if (!is_array($parts) || !isset($parts['host']) || $parts['host'] === '') {
            throw new InvalidModel(sprintf('URL "%s" is not a valid absolute URL.', $url));
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidModel(sprintf('URL "%s": credentials in a URL are not supported.', $url));
        }

        $port = $parts['port'] ?? self::DEFAULT_PORTS[$scheme];
        $authority = strtolower($parts['host']) . ($port === self::DEFAULT_PORTS[$scheme] ? '' : ':' . $port);

        return $scheme . '://' . $authority . self::withoutDotSegments($parts['path'] ?? '') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    /**
     * A reference resolved against the URL of the document holding it.
     *
     * @throws InvalidModel when the result is no absolute http(s) URL without credentials
     */
    public static function resolve(string $base, string $reference): string
    {
        $reference = explode('#', $reference, 2)[0];
        if (self::isUrl($reference)) {
            return self::normalize($reference);
        }

        $base = self::normalize($base);
        $scheme = (string) strstr($base, '://', true);
        if (strncmp($reference, '//', 2) === 0) {
            return self::normalize($scheme . ':' . $reference);
        }

        $origin = $scheme . '://' . explode('/', substr($base, strlen($scheme) + 3), 2)[0];
        [$path, $query] = self::splitQuery($reference);
        [$basePath, $baseQuery] = self::splitQuery(substr($base, strlen($origin)));
        if ($path === '') {
            $path = $basePath;
            $query ??= $baseQuery;
        } elseif (strncmp($path, '/', 1) !== 0) {
            $path = substr($basePath, 0, (int) strrpos($basePath, '/') + 1) . $path;
        }

        return self::normalize($origin . $path . ($query === null ? '' : '?' . $query));
    }

    /**
     * Whether a normalized URL is the prefix or lies below it: a prefix ending in "/" is a directory, any other one a
     * document or a directory named without its slash.
     */
    public static function isUnder(string $url, string $prefix): bool
    {
        $prefix = self::normalize($prefix);

        return $url === $prefix || strncmp($url, rtrim($prefix, '/') . '/', strlen(rtrim($prefix, '/')) + 1) === 0;
    }

    /**
     * @return array{string, ?string} the path and the query, null without a "?"
     */
    private static function splitQuery(string $reference): array
    {
        $at = strpos($reference, '?');

        return $at === false ? [$reference, null] : [(string) substr($reference, 0, $at), (string) substr($reference, $at + 1)];
    }

    private static function withoutDotSegments(string $path): string
    {
        $segments = [];
        $parts = explode('/', $path);
        foreach ($parts as $index => $segment) {
            if ($segment === '..') {
                array_pop($segments);
            } elseif ($segment !== '.' && ($segment !== '' || $index === count($parts) - 1)) {
                $segments[] = $segment;
            }
        }

        // The last segment of "a/." or "a/.." is a directory, which keeps its slash.
        $last = $parts[count($parts) - 1];

        return '/' . implode('/', $segments) . ($last === '.' || $last === '..' ? '/' : '');
    }
}
