<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Exception;

use Throwable;

/**
 * Marker for every domain exception, so outer layers can turn them into diagnostics in one catch.
 */
interface DomainError extends Throwable
{
}
