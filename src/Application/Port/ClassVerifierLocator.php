<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Port;

interface ClassVerifierLocator
{
    /**
     * A verifier over the autoload.php in the vendor-dir of the nearest Composer project at or above the directory; null
     * when that project has none or there is no project.
     */
    public function locate(string $directory): ?ClassVerifier;

    /**
     * DTO_GENERATOR_VERIFY_CLASSES=0 turns "auto" off, for an environment that must not run the consumer's code.
     */
    public function isDisabledByEnvironment(): bool;
}
