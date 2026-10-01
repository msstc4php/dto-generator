<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Exception;

use InvalidArgumentException;
use MSSTC4PHP\DtoGenerator\Domain\Target\Capability;
use MSSTC4PHP\DtoGenerator\Domain\Target\PhpVersion;

final class IncompatibleTarget extends InvalidArgumentException
{
    public static function capabilityMissing(Capability $capability, PhpVersion $php, string $feature): self
    {
        return new self(sprintf(
            '%s requires %s (PHP %s+), but the target is PHP %s.',
            $feature,
            $capability->value(),
            $capability->minimumVersion()->toString(),
            $php->toString(),
        ));
    }
}
