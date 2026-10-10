<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaGraph;

/**
 * The properties one view of a direction-dependent class keeps (spec F1 §5).
 */
final class PropertyView
{
    private Direction $direction;

    private SchemaGraph $graph;

    public function __construct(Direction $direction, SchemaGraph $graph)
    {
        $this->direction = $direction;
        $this->graph = $graph;
    }

    public function direction(): Direction
    {
        return $this->direction;
    }

    public function admits(Schema $property, Diagnostics $diagnostics): bool
    {
        $direction = Directions::of($property, $this->graph, $diagnostics);

        return !$direction instanceof Direction || $direction->equals($this->direction);
    }
}
