<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Infrastructure\Remote;

/**
 * Reads environment variables where PHP keeps them: Symfony's Dotenv fills $_SERVER and $_ENV but not getenv() by
 * default, and getenv() alone sees what neither holds when variables_order leaves them out.
 */
final class Environment
{
    public static function variable(string $name): ?string
    {
        foreach ([$_SERVER[$name] ?? null, $_ENV[$name] ?? null, getenv($name)] as $value) {
            if (is_string($value)) {
                return $value;
            }
        }

        return null;
    }
}
