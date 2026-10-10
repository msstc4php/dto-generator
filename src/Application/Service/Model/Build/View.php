<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Service\Model\Build;

use MSSTC4PHP\DtoGenerator\Domain\Builder\PropertyView;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;

/**
 * One direction's build (spec F1 §5): its properties, and the suffix its direction-dependent classes take.
 */
final class View
{
    private PropertyView $properties;

    private string $suffix;

    /** @var array<string, bool> */
    private array $dependent;

    /**
     * @param array<string, bool> $dependent locations of the schemas whose classes depend on the direction
     */
    public function __construct(PropertyView $properties, string $suffix, array $dependent)
    {
        $this->properties = $properties;
        $this->suffix = $suffix;
        $this->dependent = $dependent;
    }

    public function properties(): PropertyView
    {
        return $this->properties;
    }

    /**
     * The short name of the class a schema gives in this view.
     */
    public function name(string $short, Schema $schema): string
    {
        return ($this->dependent[$schema->location()->toString()] ?? false) ? $short . $this->suffix : $short;
    }
}
