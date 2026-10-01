<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Schema;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;

/**
 * RFC 6901 JSON pointers.
 *
 * @phpstan-import-type JsonValue from Json
 */
final class JsonPointer
{
    private function __construct()
    {
    }

    /**
     * @return list<string>
     */
    public static function segments(string $pointer): array
    {
        if ($pointer === '') {
            return [];
        }

        if (strncmp($pointer, '/', 1) !== 0 || preg_match('/~(?![01])/', $pointer) === 1) {
            throw new InvalidModel(sprintf('"%s" is not a valid JSON pointer.', $pointer));
        }

        // "~1" must be decoded before "~0", otherwise "~01" would turn into "/".
        return array_map(
            static fn (string $segment): string => str_replace(['~1', '~0'], ['/', '~'], $segment),
            explode('/', (string) substr($pointer, 1)),
        );
    }

    public static function fromSegments(string ...$segments): string
    {
        $pointer = '';
        foreach ($segments as $segment) {
            $pointer .= '/' . str_replace(['~', '/'], ['~0', '~1'], $segment);
        }

        return $pointer;
    }

    /**
     * @param array<array-key, mixed> $document
     */
    public static function has(array $document, string $pointer): bool
    {
        return self::lookup($document, $pointer) !== null;
    }

    /**
     * @param array<array-key, mixed> $document
     *
     * @return JsonValue
     */
    public static function get(array $document, string $pointer)
    {
        $found = self::lookup($document, $pointer);
        if ($found === null) {
            throw new InvalidModel(sprintf('JSON pointer "%s" does not resolve.', $pointer));
        }

        return $found[0];
    }

    /**
     * @param array<array-key, mixed> $document
     *
     * @return array{JsonValue}|null wrapped, so that a found null differs from "not found"
     */
    private static function lookup(array $document, string $pointer): ?array
    {
        $current = $document;
        foreach (self::segments($pointer) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }

            $current = $current[$segment];
        }

        return [Json::value($current)];
    }
}
