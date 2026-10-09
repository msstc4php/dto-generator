<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Builder;

/**
 * PHPDoc literal types (`'a'`, `5`, `true`) for `const` and mixed enums.
 */
final class LiteralType
{
    // Left unrefined rather than escaped: quotes, backslashes, `|` and `*` would need escaping that not every PHPDoc
    // reader understands, `*/` would end the docblock, and `{@` starts an inline tag.
    private const SAFE_STRING = '/\A[A-Za-z0-9 _.,:;!?#$%&+=<>()\[\]~^\/-]*\z/';

    private const LITERAL = "(?:'[^']*'|-?\\d+|true|false)";

    private function __construct()
    {
    }

    /**
     * @param int|string|bool $value
     */
    public static function of($value): ?string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        return preg_match(self::SAFE_STRING, $value) === 1 ? "'" . $value . "'" : null;
    }

    /**
     * Null when a value is unsafe or there is none.
     *
     * @param array<int|string|bool> $values
     */
    public static function union(array $values): ?string
    {
        if ($values === []) {
            return null;
        }

        $literals = [];
        foreach ($values as $value) {
            $literal = self::of($value);
            if ($literal === null) {
                return null;
            }

            $literals[] = $literal;
        }

        return implode('|', $literals);
    }

    /**
     * Whether a refinement built by union() admits the value; null for any other refinement.
     *
     * @param mixed $value
     */
    public static function admits(string $refinement, $value): ?bool
    {
        $literals = self::literals($refinement);
        if ($literals === null) {
            return null;
        }

        $own = is_int($value) || is_string($value) || is_bool($value) ? self::of($value) : null;

        return $own !== null && in_array($own, $literals, true);
    }

    /**
     * The literals of a refinement built by union(), or null for any other refinement.
     *
     * @return list<string>|null
     */
    public static function literals(string $refinement): ?array
    {
        if (preg_match('/\A' . self::LITERAL . '(?:\|' . self::LITERAL . ')*\z/', $refinement) !== 1) {
            return null;
        }

        preg_match_all('/' . self::LITERAL . '/', $refinement, $matches);

        return $matches[0];
    }
}
