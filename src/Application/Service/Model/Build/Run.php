<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Service\Model\Build;

use MSSTC4PHP\DtoGenerator\Domain\Builder\Composition;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;

/**
 * One build of the graph, with the object schemas it met: those it built and those whose name another took.
 */
final class Run
{
    private Output $output;

    /** @var list<array{Schema, Composition}> */
    private array $classSchemas;

    /**
     * @param list<array{Schema, Composition}> $classSchemas
     */
    public function __construct(Output $output, array $classSchemas)
    {
        $this->output = $output;
        $this->classSchemas = $classSchemas;
    }

    public function output(): Output
    {
        return $this->output;
    }

    /**
     * @return list<array{Schema, Composition}>
     */
    public function classSchemas(): array
    {
        return $this->classSchemas;
    }
}
