<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Infrastructure\Environment;

use MSSTC4PHP\DtoGenerator\Application\Port\ClassVerifier;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;

/**
 * Loads the consumer's autoloader into this process, which runs the consumer's code (spec §9.4).
 */
final class AutoloadClassVerifier implements ClassVerifier
{
    public function __construct(string $autoload)
    {
        require_once $autoload;
    }

    public function hasClass(ClassName $class): bool
    {
        // Enums are classes to class_exists().
        return class_exists($class->fqcn()) || interface_exists($class->fqcn());
    }

    public function hasConstant(?ClassName $class, string $name): bool
    {
        if (!$class instanceof ClassName) {
            return defined($name);
        }

        return $this->hasClass($class) && defined($class->fqcn() . '::' . $name);
    }
}
