<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Shared;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;

/**
 * Host for the JSON value type aliases; never instantiated.
 *
 * A decoded JSON scalar:
 *
 * @phpstan-type JsonScalar null|bool|int|float|string
 *
 * A decoded JSON value. Reason for `mixed`: PHPStan type aliases cannot be recursive.
 * @phpstan-type JsonValue JsonScalar|array<array-key, mixed>
 */
final class Json
{
    private function __construct()
    {
    }

    /**
     * @param array<array-key, mixed> $array
     *
     * @phpstan-assert-if-true list<mixed> $array
     */
    public static function isList(array $array): bool
    {
        $expected = 0;
        foreach (array_keys($array) as $key) {
            if ($key !== $expected) {
                return false;
            }

            $expected++;
        }

        return true;
    }

    /**
     * Narrows a decoded value; decoders never produce objects or resources, so those are rejected.
     *
     * @param mixed $value
     *
     * @return JsonValue
     */
    public static function value($value)
    {
        if ($value === null || is_scalar($value) || is_array($value)) {
            return $value;
        }

        throw new InvalidModel(sprintf('%s is not a JSON value.', is_object($value) ? get_class($value) : gettype($value)));
    }

    /**
     * Shortest decimal form of a YAML/JSON float, unaffected by serialize_precision and the locale.
     */
    public static function floatToString(float $value): string
    {
        $text = rtrim(sprintf('%.14F', $value), '0');

        return substr($text, -1) === '.' ? $text . '0' : $text;
    }
}
