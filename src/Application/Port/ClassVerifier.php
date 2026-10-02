<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Port;

use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;

/**
 * Asks the consuming project's autoloader whether the classes and constants of attributes exist (spec §7.1).
 */
interface ClassVerifier
{
    public function hasClass(ClassName $class): bool;

    /**
     * @param ClassName|null $class null for a global constant, whose name may carry a namespace
     */
    public function hasConstant(?ClassName $class, string $name): bool;
}
