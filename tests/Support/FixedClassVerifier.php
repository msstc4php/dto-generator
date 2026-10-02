<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Support;

use MSSTC4PHP\DtoGenerator\Application\Port\ClassVerificationFailed;
use MSSTC4PHP\DtoGenerator\Application\Port\ClassVerifier;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;

/**
 * A consumer that has exactly the given classes, other types (interfaces) and constants (`Class::NAME` or a global
 * `NAME`), or whose autoloader fails on every question.
 */
final class FixedClassVerifier implements ClassVerifier
{
    /** @var list<string> */
    private array $classes;

    /** @var list<string> */
    private array $constants;

    /** @var list<string> */
    private array $types;

    private ?string $failure;

    /** @var list<string> */
    public array $asked = [];

    /**
     * @param list<string> $classes
     * @param list<string> $constants
     * @param list<string> $types names that are types but not classes
     */
    public function __construct(array $classes, array $constants = [], array $types = [], ?string $failure = null)
    {
        $this->classes = $classes;
        $this->constants = $constants;
        $this->types = $types;
        $this->failure = $failure;
    }

    public function hasClass(ClassName $class): bool
    {
        $this->ask('class ' . $class->fqcn());

        return in_array($class->fqcn(), $this->classes, true);
    }

    public function hasType(ClassName $class): bool
    {
        $this->ask('type ' . $class->fqcn());

        return in_array($class->fqcn(), array_merge($this->classes, $this->types), true);
    }

    public function hasConstant(?ClassName $class, string $name): bool
    {
        $constant = ($class instanceof ClassName ? $class->fqcn() . '::' : '') . $name;
        $this->ask('constant ' . $constant);

        return in_array($constant, $this->constants, true);
    }

    private function ask(string $question): void
    {
        $this->asked[] = $question;
        if ($this->failure !== null) {
            throw new ClassVerificationFailed($this->failure);
        }
    }
}
