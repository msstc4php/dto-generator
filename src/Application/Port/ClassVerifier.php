<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Port;

use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;

/**
 * Asks the consuming project's autoloader whether the classes and constants of attributes exist (spec §7.1).
 */
interface ClassVerifier
{
    /**
     * A class or enum: what an attribute or `new` can name.
     *
     * @throws ClassVerificationFailed
     */
    public function hasClass(ClassName $class): bool;

    /**
     * A class, enum, interface or trait: what `Name::class` can name.
     *
     * @throws ClassVerificationFailed
     */
    public function hasType(ClassName $class): bool;

    /**
     * @param ClassName|null $class null for a global constant, whose name may carry a namespace
     *
     * @throws ClassVerificationFailed
     */
    public function hasConstant(?ClassName $class, string $name): bool;
}
