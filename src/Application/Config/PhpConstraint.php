<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Config;

final class PhpConstraint
{
    private function __construct()
    {
    }

    /**
     * Lowest "major.minor" a Composer constraint admits, or null when no alternative is bounded from below
     * (e.g. "*", "<8.0", "!=7.4").
     */
    public static function lowestMinor(string $constraint): ?string
    {
        $lowest = null;
        foreach (explode('|', $constraint) as $alternative) {
            $bound = self::lowerBound($alternative);
            if ($bound !== null && ($lowest === null || $bound < $lowest)) {
                $lowest = $bound;
            }
        }

        return $lowest === null ? null : $lowest[0] . '.' . $lowest[1];
    }

    /**
     * The tightest lower bound among the AND-ed parts of one alternative; "<", "<=" and "!=" give none.
     *
     * @return array{int, int}|null
     */
    private static function lowerBound(string $alternative): ?array
    {
        // Composer allows "<= 8.0"; gluing the operator back keeps "8.0" from reading as a bare lower bound.
        $alternative = (string) preg_replace('/(<>|!=|[<>]=?|==?)\s+/', '$1', $alternative);
        preg_match_all('/[^\s,]+/', $alternative, $tokens);
        $bound = null;
        foreach ($tokens[0] as $token) {
            // In "a - b" only "a" bounds from below.
            if ($token === '-') {
                break;
            }

            if (preg_match('/^(?:>=?|\^|~|==?)?v?(?<major>\d+)(?:\.(?<minor>\d+))?/', $token, $matches) !== 1) {
                continue;
            }

            $candidate = [(int) $matches['major'], isset($matches['minor']) ? (int) $matches['minor'] : 0];
            if ($bound === null || $candidate > $bound) {
                $bound = $candidate;
            }
        }

        return $bound;
    }
}
