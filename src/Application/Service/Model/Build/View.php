<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Service\Model\Build;

use MSSTC4PHP\DtoGenerator\Domain\Builder\Direction;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;

/**
 * One direction's build (spec F1 §5): the direction, and the suffix its direction-dependent classes take.
 */
final class View
{
    private Direction $direction;

    private string $suffix;

    /** @var array<string, bool> */
    private array $dependent;

    /**
     * @param array<string, bool> $dependent locations of the schemas whose classes depend on the direction
     */
    public function __construct(Direction $direction, string $suffix, array $dependent)
    {
        $this->direction = $direction;
        $this->suffix = $suffix;
        $this->dependent = $dependent;
    }

    public function direction(): Direction
    {
        return $this->direction;
    }

    /**
     * The short name of the class a schema gives in this view.
     */
    public function name(string $short, Schema $schema): string
    {
        return ($this->dependent[$schema->location()->toString()] ?? false) ? $short . $this->suffix : $short;
    }
}
