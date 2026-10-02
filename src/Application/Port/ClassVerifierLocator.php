<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Port;

interface ClassVerifierLocator
{
    /**
     * A verifier over the nearest vendor/autoload.php from the directory upwards; null when there is none.
     */
    public function locate(string $directory): ?ClassVerifier;

    /**
     * DTO_GENERATOR_VERIFY_CLASSES=0 turns "auto" off, for an environment that must not run the consumer's code.
     */
    public function isDisabledByEnvironment(): bool;
}
