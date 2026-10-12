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

    private ReadWriteMode $mode;

    public function __construct(Direction $direction, Directions $directions, ReadWriteMode $mode)
    {
        $this->direction = $direction;
        $this->directions = $directions;
        $this->mode = $mode;
    }

    public function direction(): Direction
    {
        return $this->direction;
    }

    /**
     * Whether the view leaves out properties of the class: a class kept as one model has all of them in each view.
     */
    public function appliesTo(Schema $class): bool
    {
        return $this->mode->splits($class);
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
