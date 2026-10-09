<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Builder;

/**
 * PHPDoc literal types (`'a'`, `5`, `true`) for `const` and mixed enums.
 */
final class LiteralType
{
    // Left unrefined rather than escaped: quotes, backslashes, `|` and `*` would need escaping that not every PHPDoc
    // reader understands, and `*/` would end the docblock.
    private const SAFE_STRING = '/\A[A-Za-z0-9 _.,:;!?@#$%&+=<>()\[\]{}~^\/-]*\z/';

    private const LITERAL = "/\\A(?:'[^']*'|-?\\d+|true|false)\\z/";

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
     * @param non-empty-list<int|string|bool> $values
     */
    public static function union(array $values): ?string
    {
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
        $literals = explode('|', $refinement);
        foreach ($literals as $literal) {
            if (preg_match(self::LITERAL, $literal) !== 1) {
                return null;
            }
        }

        $own = is_int($value) || is_string($value) || is_bool($value) ? self::of($value) : null;

        return $own !== null && in_array($own, $literals, true);
    }
}
