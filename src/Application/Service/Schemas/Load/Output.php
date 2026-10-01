<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Service\Schemas\Load;

use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaGraph;

final class Output
{
    private SchemaGraph $graph;

    private Diagnostics $diagnostics;

    public function __construct(SchemaGraph $graph, Diagnostics $diagnostics)
    {
        $this->graph = $graph;
        $this->diagnostics = $diagnostics;
    }

    public function graph(): SchemaGraph
    {
        return $this->graph;
    }

    public function diagnostics(): Diagnostics
    {
        return $this->diagnostics;
    }
}
