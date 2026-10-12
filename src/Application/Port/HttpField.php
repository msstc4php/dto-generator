<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Port;

/**
 * The grammar of HTTP header fields (RFC 9110 §5), shared by the headers a config sets and the answers a server sends.
 */
final class HttpField
{
    /** A field name, for patterns delimited by "/". */
    public const TOKEN = "[!#$%&'*+.^_`|~0-9A-Za-z-]+";

    private const OWN = ['host', 'connection', 'content-length', 'transfer-encoding'];

    public static function isName(string $name): bool
    {
        return preg_match('/^' . self::TOKEN . '\z/', $name) === 1;
    }

    /**
     * A tab is allowed in a field value (RFC 9110 §5.5), line breaks would end it.
     */
    public static function hasControlCharacters(string $value): bool
    {
        return preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $value) === 1;
    }

    /**
     * Whether the client or a proxy writes the field: another value would make the request ambiguous.
     */
    public static function isReserved(string $name): bool
    {
        $lower = strtolower($name);

        return in_array($lower, self::OWN, true) || strncmp($lower, 'proxy-', 6) === 0;
    }
}
