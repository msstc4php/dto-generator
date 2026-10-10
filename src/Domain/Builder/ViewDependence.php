<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Model\ClassModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassType;
use MSSTC4PHP\DtoGenerator\Domain\Model\DiscriminatorModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ListType;
use MSSTC4PHP\DtoGenerator\Domain\Model\MapType;
use MSSTC4PHP\DtoGenerator\Domain\Model\NullableType;
use MSSTC4PHP\DtoGenerator\Domain\Model\TypeModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\UnionType;

/**
 * The classes whose model depends on the direction (spec F1 §4): those with a directed property of their own, and
 * those that hold, extend or list as a variant a class that depends on it.
 */
final class ViewDependence
{
    private function __construct()
    {
    }

    /**
     * @param list<ClassModel> $classes
     * @param array<string, true> $directed FQCN of the classes with a directed property of their own
     *
     * @return array<string, true> FQCN of every class that depends on the direction
     */
    public static function of(array $classes, array $directed): array
    {
        $dependent = $directed;
        do {
            $grown = false;
            foreach ($classes as $class) {
                $fqcn = $class->name()->fqcn();
                if (!isset($dependent[$fqcn]) && self::reachesAny(self::neighbours($class), $dependent)) {
                    $dependent[$fqcn] = true;
                    $grown = true;
                }
            }
        } while ($grown);

        return $dependent;
    }

    /**
     * @param list<ClassName> $names
     * @param array<string, true> $dependent
     */
    private static function reachesAny(array $names, array $dependent): bool
    {
        foreach ($names as $name) {
            if (isset($dependent[$name->fqcn()])) {
                return true;
            }
        }

        return false;
    }

    /**
     * The classes whose views this class must use: those its properties hold, its parent and its variants.
     *
     * @return list<ClassName>
     */
    private static function neighbours(ClassModel $class): array
    {
        $names = [];
        foreach ($class->properties() as $property) {
            $names = array_merge($names, self::classesOf($property->type()));
        }

        $parent = $class->parent();
        if ($parent instanceof ClassName) {
            $names[] = $parent;
        }

        $discriminator = $class->discriminator();

        return $discriminator instanceof DiscriminatorModel ? array_merge($names, array_values($discriminator->mapping())) : $names;
    }

    /**
     * @return list<ClassName>
     */
    private static function classesOf(TypeModel $type): array
    {
        if ($type instanceof ClassType) {
            return [$type->className()];
        }

        if ($type instanceof ListType) {
            return self::classesOf($type->item());
        }

        if ($type instanceof MapType) {
            return self::classesOf($type->value());
        }

        if ($type instanceof NullableType) {
            return self::classesOf($type->inner());
        }

        $names = [];
        foreach ($type instanceof UnionType ? $type->members() : [] as $member) {
            $names = array_merge($names, self::classesOf($member));
        }

        return $names;
    }
}
