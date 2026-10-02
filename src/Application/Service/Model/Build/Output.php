<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Service\Model\Build;

use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Model\PropertyModel;

final class Output
{
    /** @var list<BuiltClass> */
    private array $classes;

    private Diagnostics $diagnostics;

    /** @var list<BuiltEnum> */
    private array $enums;

    /** @var array<string, ClassModel> */
    private array $models = [];

    /**
     * @param list<BuiltClass> $classes in graph order, inline classes after their owners
     * @param list<BuiltEnum> $enums
     */
    public function __construct(array $classes, Diagnostics $diagnostics, array $enums)
    {
        $this->classes = $classes;
        foreach ($classes as $built) {
            $this->models[$built->model()->name()->fqcn()] = $built->model();
        }

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

    /**
     * The properties a class inherits, root ancestor first: its constructor takes them before its own.
     *
     * @return list<PropertyModel>
     */
    public function inheritedProperties(ClassModel $class): array
    {
        $models = $this->models;
        // The build breaks inheritance loops, but the chain still stops where one would close.
        $seen = [$class->name()->fqcn() => $class->name()];
        $chain = [];
        for ($parent = $class->parent(); $parent instanceof ClassName && isset($models[$parent->fqcn()]) && !isset($seen[$parent->fqcn()]); $parent = $models[$parent->fqcn()]->parent()) {
            $seen[$parent->fqcn()] = $parent;
            $chain[] = $models[$parent->fqcn()]->properties();
        }

        return array_merge([], ...array_reverse($chain));
    }

    public function diagnostics(): Diagnostics
    {
        return $this->diagnostics;
    }
}
