<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Support;

use MSSTC4PHP\DtoGenerator\Application\Port\ClassVerifier;
use MSSTC4PHP\DtoGenerator\Application\Port\ClassVerifierLocator;

final class FixedClassVerifierLocator implements ClassVerifierLocator
{
    private ?ClassVerifier $verifier;

    private bool $disabled;

    public function __construct(?ClassVerifier $verifier = null, bool $disabled = false)
    {
        $this->verifier = $verifier;
        $this->disabled = $disabled;
    }

    public function locate(string $directory): ?ClassVerifier
    {
        return $this->verifier;
    }

    public function isDisabledByEnvironment(): bool
    {
        return $this->disabled;
    }
}
