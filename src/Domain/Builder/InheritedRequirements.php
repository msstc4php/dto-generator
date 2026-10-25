<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Model\ClassModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;

/**
 * The inherited properties each class requires though its ancestors leave them optional: what the class and its
 * ancestors list, as the constructor of a class passes them on to its parent's.
 */
final class InheritedRequirements
{
    private function __construct()
    {
    }

    /**
     * Of what the chain lists, only the optional properties an ancestor has: one it skips, or a view leaves out, is not
     * there, and one the class declares itself (a redeclaration left as an error, or merged instead) is its own.
     *
     * @param array<string, ClassModel> $models by FQCN, parents final
     *
     * @return array<string, ClassModel>
     */
    public static function settle(array $models): array
    {
        $settled = [];
        foreach ($models as $fqcn => $model) {
            $optional = [];
            $listed = $model->requiredInherited();
            // Root first, as the constructor takes them.
            foreach (self::ancestorsOf($fqcn, $models) as $ancestor) {
                array_push($listed, ...$ancestor->requiredInherited());
                foreach ($ancestor->properties() as $property) {
                    if (!$property->isRequired()) {
                        $optional[$property->wireName()] = $property->wireName();
                    }
                }
            }

            foreach ($model->properties() as $property) {
                unset($optional[$property->wireName()]);
            }

            $settled[$fqcn] = $model->withRequiredInherited(...array_values(array_intersect($optional, $listed)));
        }

        return $settled;
    }

    /**
     * @param array<string, ClassModel> $models
     *
     * @return list<ClassModel> root first; a loop, which the build breaks, stops the chain
     */
    private static function ancestorsOf(string $fqcn, array $models): array
    {
        $ancestors = [];
        $seen = [$fqcn => $fqcn];
        $parent = $models[$fqcn]->parent();
        while ($parent instanceof ClassName && isset($models[$parent->fqcn()]) && !isset($seen[$parent->fqcn()])) {
            $seen[$parent->fqcn()] = $parent->fqcn();
            $ancestors[] = $models[$parent->fqcn()];
            $parent = $models[$parent->fqcn()]->parent();
        }

        return array_reverse($ancestors);
    }
}
