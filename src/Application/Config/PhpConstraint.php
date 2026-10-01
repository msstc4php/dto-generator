<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Config;

final class PhpConstraint
{
    private function __construct()
    {
    }

    /**
     * Lowest "major.minor" a Composer constraint admits; the first number of each alternative is its lower
     * bound for every operator users put on "php" (>=, ^, ~, x.y.*). Null when no version is named.
     */
    public static function lowestMinor(string $constraint): ?string
    {
        $lowest = null;
        $alternatives = preg_split('/\s*\|\|?\s*/', trim($constraint));
        foreach ($alternatives === false ? [] : $alternatives as $alternative) {
            if (preg_match('/(\d+)(?:\.(\d+))?/', $alternative, $matches) !== 1) {
                continue;
            }

            $candidate = [(int) $matches[1], isset($matches[2]) ? (int) $matches[2] : 0];
            if ($lowest === null || $candidate < $lowest) {
                $lowest = $candidate;
            }
        }

        return $lowest === null ? null : $lowest[0] . '.' . $lowest[1];
    }
}
