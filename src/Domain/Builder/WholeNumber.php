<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Builder;

/**
 * JSON Schema counts 5.0 as the integer 5: a whole float PHP's int holds is that int.
 */
final class WholeNumber
{
    private function __construct()
    {
    }

    public static function of(float $value): ?int
    {
        // Range first: PHP 8.5 warns about casting a float int cannot hold. PHP_INT_MAX compares as 2^63, outside.
        if ($value < PHP_INT_MIN || $value >= PHP_INT_MAX || fmod($value, 1.0) !== 0.0) {
            return null;
        }

        return (int) $value;
    }
}
