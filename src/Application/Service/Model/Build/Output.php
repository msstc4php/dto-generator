<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Service\Model\Build;

use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;

final class Output
{
    /** @var list<BuiltClass> */
    private array $classes;

    private Diagnostics $diagnostics;

    /** @var list<BuiltEnum> */
    private array $enums;

    /**
     * @param list<BuiltClass> $classes in graph order, inline classes after their owners
     * @param list<BuiltEnum> $enums
     */
    public function __construct(array $classes, Diagnostics $diagnostics, array $enums)
    {
        $this->classes = $classes;
        $this->diagnostics = $diagnostics;
        $this->enums = $enums;
    }

    /**
     * @return list<BuiltEnum>
     */
    public function enums(): array
    {
        return $this->enums;
    }

    /**
     * @return list<BuiltClass>
     */
    public function classes(): array
    {
        return $this->classes;
    }

    public function diagnostics(): Diagnostics
    {
        return $this->diagnostics;
    }
}
