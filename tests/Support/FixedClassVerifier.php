<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Support;

use MSSTC4PHP\DtoGenerator\Application\Port\ClassVerifier;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;

/**
 * A consumer that has exactly the given classes and constants (`Class::NAME` or a global `NAME`).
 */
final class FixedClassVerifier implements ClassVerifier
{
    /** @var list<string> */
    private array $classes;

    /** @var list<string> */
    private array $constants;

    /**
     * @param list<string> $classes
     * @param list<string> $constants
     */
    public function __construct(array $classes, array $constants = [])
    {
        $this->classes = $classes;
        $this->constants = $constants;
    }

    public function hasClass(ClassName $class): bool
    {
        return in_array($class->fqcn(), $this->classes, true);
    }

    public function hasConstant(?ClassName $class, string $name): bool
    {
        return in_array(($class instanceof ClassName ? $class->fqcn() . '::' : '') . $name, $this->constants, true);
    }
}
