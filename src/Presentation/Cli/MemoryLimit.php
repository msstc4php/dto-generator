<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Presentation\Cli;

/**
 * Reads memory_limit quantities the way php.ini writes them; anything else is left alone rather than guessed.
 */
final class MemoryLimit
{
    private const UNITS = ['' => 1, 'k' => 1024, 'm' => 1024 ** 2, 'g' => 1024 ** 3];

    private function __construct()
    {
    }

    public static function isBelow(string $current, string $target): bool
    {
        $currentBytes = self::bytes($current);
        $targetBytes = self::bytes($target);

        return $currentBytes !== null && $targetBytes !== null && $currentBytes < $targetBytes;
    }

    /**
     * @return int|null PHP_INT_MAX for no limit or one beyond counting, null for a form other than digits and a unit
     */
    public static function bytes(string $quantity): ?int
    {
        if ($quantity === '-1') {
            return PHP_INT_MAX;
        }

        if (preg_match('~^(?<count>\d+)(?<unit>[kmg]?)$~iD', $quantity, $matches) !== 1) {
            return null;
        }

        $unit = self::UNITS[strtolower($matches['unit'])];
        // (int) of a digit string beyond PHP_INT_MAX saturates, so the comparison stays exact.
        $count = (int) $matches['count'];

        return $count > intdiv(PHP_INT_MAX, $unit) ? PHP_INT_MAX : $count * $unit;
    }
}
