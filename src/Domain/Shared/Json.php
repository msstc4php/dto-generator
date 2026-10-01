<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Shared;

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
}
