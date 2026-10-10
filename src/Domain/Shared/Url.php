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

    private const UNRESERVED = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-._~';

    /** RFC 3986 reserved and unreserved characters, and "%" of an escape. */
    private const CHARACTERS = '#^[A-Za-z0-9\-._~:/?\#\[\]@!$&\'()*+,;=%]*$#D';

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
     * Whether the string starts with a scheme ("file:", "mailto:" too), which a reference in a remote document may name.
     */
    public static function hasScheme(string $value): bool
    {
        return preg_match('#^[A-Za-z][A-Za-z0-9+.\-]*:#', $value) === 1;
    }

    /**
     * Scheme and host in lower case, no default port, escapes of unreserved characters decoded and the others in upper
     * case, no dot segments, no empty query, no fragment: one spelling per document, the one the allow list checks and
     * the one fetched.
     *
     * @throws InvalidModel unless it is an absolute http(s) URL without credentials, of RFC 3986 characters only and
     *                      without an encoded "/" or "\" in its path
     */
    public static function normalize(string $url): string
    {
        $url = explode('#', $url, 2)[0];
        $scheme = self::hasScheme($url) ? strtolower((string) strstr($url, ':', true)) : '';
        if ($scheme === '') {
            throw new InvalidModel(sprintf('URL "%s" is not a valid absolute URL.', $url));
        }

        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new InvalidModel(sprintf('URL "%s": only http and https are supported.', $url));
        }

        // parse_url() would quietly turn other characters into "_", and a space or a line break would reach the request.
        if (preg_match(self::CHARACTERS, $url) !== 1 || preg_match('#%(?![0-9A-Fa-f]{2})#', $url) === 1) {
            throw new InvalidModel(sprintf('URL "%s" has characters a URL cannot hold; percent-encode them.', $url));
        }

        $parts = self::isUrl($url) ? parse_url($url) : false;
        if (!is_array($parts) || !isset($parts['host']) || $parts['host'] === '') {
            throw new InvalidModel(sprintf('URL "%s" is not a valid absolute URL.', $url));
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidModel(sprintf('URL "%s": credentials in a URL are not supported.', $url));
        }

        // A reg-name or an IP literal: parse_url() leaves "good.com:80" of "good.com:80:80" in the host.
        if (preg_match('#^(?:[a-z0-9\-._~!$&\'()*+,=%]+|\[[0-9a-f:.]+\])\z#i', $parts['host']) !== 1) {
            throw new InvalidModel(sprintf('URL "%s" is not a valid absolute URL.', $url));
        }

        $path = self::canonicalEscapes($parts['path'] ?? '');
        // Servers decode, drop or double-decode these into separators or dot segments ("..%2F", "..;", "%252e%252e"), so
        // each would climb out of an allowed prefix.
        if (preg_match('#%2F|%5C|%25|%00|;#', $path) === 1 || preg_match('//u', rawurldecode($path)) !== 1) {
            throw new InvalidModel(sprintf('URL "%s": an encoded "/", "\\", "%%" or NUL, a ";" or bytes that are not UTF-8 in the path are not supported.', $url));
        }

        $port = $parts['port'] ?? self::DEFAULT_PORTS[$scheme];
        $authority = strtolower($parts['host']) . ($port === self::DEFAULT_PORTS[$scheme] ? '' : ':' . $port);
        // PHP 8 keeps an empty query, 7.4 drops it; one spelling keeps cache entries shared between versions.
        $query = self::canonicalEscapes($parts['query'] ?? '');

        return $scheme . '://' . $authority . self::withoutDotSegments($path) . ($query === '' ? '' : '?' . $query);
    }

    /**
     * A reference resolved against the URL of the document holding it.
     *
     * @throws InvalidModel when the result is no absolute http(s) URL without credentials
     */
    public static function resolve(string $base, string $reference): string
    {
        $reference = explode('#', $reference, 2)[0];
        // Any scheme, so "file:/etc/passwd" in a remote document is refused rather than read as a relative path.
        if (self::hasScheme($reference)) {
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

    /**
     * Escapes of unreserved characters decoded (RFC 3986 §6.2.2.2), so "%2e%2e" is the ".." it means; others in upper
     * case.
     */
    private static function canonicalEscapes(string $value): string
    {
        return (string) preg_replace_callback(
            '#%([0-9A-Fa-f]{2})#',
            static function (array $escape): string {
                $character = chr((int) hexdec($escape[1]));

                return strspn($character, self::UNRESERVED) === 1 ? $character : '%' . strtoupper($escape[1]);
            },
            $value,
        );
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

        // The last segment of "a/." or "a/.." is a directory, which keeps its slash; above the root there is the root.
        $last = $parts[count($parts) - 1];
        $joined = implode('/', $segments) . ($last === '.' || $last === '..' ? '/' : '');

        return '/' . ltrim($joined, '/');
    }
}
