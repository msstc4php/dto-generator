<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Service\Model\Enrich;

use MSSTC4PHP\DtoGenerator\Application\Service\Model\Build\BuiltClass;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;

final class Output
{
    /** @var list<BuiltClass> */
    private array $classes;

    private Diagnostics $diagnostics;

    /**
     * @param list<BuiltClass> $classes the same classes, with the attributes of every enricher
     */
    public function __construct(array $classes, Diagnostics $diagnostics)
    {
        $this->classes = $classes;
        $this->diagnostics = $diagnostics;
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
