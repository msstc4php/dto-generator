<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;

/**
 * The properties one view of a direction-dependent class keeps (spec F1 §5).
 */
final class PropertyView
{
    private Direction $direction;

    private Directions $directions;

    public function __construct(Direction $direction, Directions $directions)
    {
        $this->direction = $direction;
        $this->directions = $directions;
    }

    public function direction(): Direction
    {
        return $this->direction;
    }

    public function admits(Schema $property): bool
    {
        $direction = $this->directions->of($property);

        return !$direction instanceof Direction || $direction->equals($this->direction);
    }

    /**
     * The properties of the other view among a class's declarations, whichever member of its composition marks them.
     *
     * @param list<array{string, Schema}> $sources wire name and schema of each declaration
     *
     * @return array<array-key, string> wire name → wire name; a numeric one is an integer key
     */
    public function excluded(array $sources): array
    {
        $excluded = [];
        foreach ($this->directions->ofSources($sources) as [$wireName, $direction]) {
            if (!$direction->equals($this->direction)) {
                $excluded[$wireName] = $wireName;
            }
        }

        return $excluded;
    }
}
