<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Builder;

use Generator;
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
     * @param array<string, bool> $directed FQCN of the classes with a directed property of their own
     *
     * @return array<string, bool> FQCN of every class that depends on the direction
     */
    public static function of(array $classes, array $directed): array
    {
        // Who must follow whom: from each class to the classes that hold, extend or list it.
        $followers = [];
        foreach ($classes as $class) {
            foreach (self::neighbours($class) as $neighbour) {
                $followers[$neighbour->fqcn()][] = $class->name()->fqcn();
            }
        }

        $dependent = [];
        $pending = array_keys(array_filter($directed));
        // The queue grows while it is read.
        for ($next = 0; isset($pending[$next]); $next++) {
            $fqcn = $pending[$next];
            if (!($dependent[$fqcn] ?? false)) {
                $dependent[$fqcn] = true;
                foreach ($followers[$fqcn] ?? [] as $follower) {
                    $pending[] = $follower;
                }
            }
        }

        return $dependent;
    }

    /**
     * The classes whose views this class must use: those its properties hold, its parent and its variants.
     *
     * @return Generator<int, ClassName, mixed, void>
     */
    private static function neighbours(ClassModel $class): Generator
    {
        foreach ($class->properties() as $property) {
            yield from self::classesOf($property->type());
        }

        $parent = $class->parent();
        if ($parent instanceof ClassName) {
            yield $parent;
        }

        $discriminator = $class->discriminator();
        foreach ($discriminator instanceof DiscriminatorModel ? $discriminator->mapping() : [] as $variant) {
            yield $variant;
        }
    }

    /**
     * @return Generator<int, ClassName, mixed, void>
     */
    private static function classesOf(TypeModel $type): Generator
    {
        if ($type instanceof ClassType) {
            yield $type->className();
        } elseif ($type instanceof ListType) {
            yield from self::classesOf($type->item());
        } elseif ($type instanceof MapType) {
            yield from self::classesOf($type->value());
        } elseif ($type instanceof NullableType) {
            yield from self::classesOf($type->inner());
        } elseif ($type instanceof UnionType) {
            foreach ($type->members() as $member) {
                yield from self::classesOf($member);
            }
        }
    }
}
