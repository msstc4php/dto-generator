<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassKind;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Model\DiscriminatorModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\DiscriminatorValues;
use MSSTC4PHP\DtoGenerator\Domain\Model\EnumBacking;
use MSSTC4PHP\DtoGenerator\Domain\Model\EnumType;
use MSSTC4PHP\DtoGenerator\Domain\Model\MixedType;
use MSSTC4PHP\DtoGenerator\Domain\Model\NullableType;
use MSSTC4PHP\DtoGenerator\Domain\Model\PropertyModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ScalarType;
use MSSTC4PHP\DtoGenerator\Domain\Model\TypeModel;

/**
 * The discriminator values that select each final class: for every discriminator of its ancestors, the mapping keys
 * of the class itself or of an abstract ancestor below the discriminated base.
 */
final class SelectingValues
{
    private function __construct()
    {
    }

    /**
     * @param array<string, ClassModel> $models by FQCN, linked: kinds, parents and discriminators are settled
     *
     * @return array<string, non-empty-list<DiscriminatorValues>> by FQCN
     */
    public static function of(array $models, Diagnostics $diagnostics): array
    {
        $selected = [];
        foreach ($models as $fqcn => $model) {
            if (!$model->kind()->equals(ClassKind::from(ClassKind::FINAL))) {
                continue;
            }

            $values = self::forClass($model, self::ancestors($model, $models), $diagnostics);
            if ($values !== []) {
                $selected[$fqcn] = $values;
            }
        }

        return $selected;
    }

    /**
     * @param array<string, ClassModel> $models
     *
     * @return list<ClassModel> nearest first
     */
    private static function ancestors(ClassModel $model, array $models): array
    {
        $ancestors = [];
        $seen = [$model->name()->fqcn() => true];
        for ($parent = $model->parent(); $parent instanceof ClassName && isset($models[$parent->fqcn()]) && !isset($seen[$parent->fqcn()]); $parent = $models[$parent->fqcn()]->parent()) {
            $seen[$parent->fqcn()] = true;
            $ancestors[] = $models[$parent->fqcn()];
        }

        return $ancestors;
    }

    /**
     * When two discriminators read one property, the nearest decides: an outer mapping can only name the inner base,
     * whose own discriminator then reads the same value again.
     *
     * @param list<ClassModel> $ancestors nearest first
     *
     * @return list<DiscriminatorValues> root discriminator first
     */
    private static function forClass(ClassModel $model, array $ancestors, Diagnostics $diagnostics): array
    {
        $byWireName = [];
        foreach (array_merge([$model], $ancestors) as $class) {
            foreach ($class->properties() as $property) {
                $byWireName[$property->wireName()] = $property;
            }
        }

        $found = [];
        foreach ($ancestors as $index => $base) {
            $discriminator = $base->discriminator();
            // A class without the property, or one the mapping leaves out, was reported while linking.
            $property = $discriminator instanceof DiscriminatorModel ? $byWireName[$discriminator->propertyName()] ?? null : null;
            if (!$discriminator instanceof DiscriminatorModel || !$property instanceof PropertyModel || isset($found[$property->name()])) {
                continue;
            }

            $keys = self::keysSelecting($model, array_slice($ancestors, 0, $index), $discriminator);
            $values = $keys === [] ? null : self::typed($keys, $property, $model, $diagnostics);
            if ($values instanceof DiscriminatorValues) {
                $found[$property->name()] = $values;
            }
        }

        return array_reverse(array_values($found));
    }

    /**
     * @param list<ClassModel> $between the ancestors below the discriminated base
     *
     * @return list<int|string>
     */
    private static function keysSelecting(ClassModel $model, array $between, DiscriminatorModel $discriminator): array
    {
        $targets = [$model->name()->fqcn() => true];
        foreach ($between as $ancestor) {
            // An open ancestor is a class of its own: its value selects it, not its subclasses.
            if ($ancestor->kind()->isAbstract()) {
                $targets[$ancestor->name()->fqcn()] = true;
            }
        }

        $keys = [];
        foreach ($discriminator->mapping() as $key => $target) {
            if (isset($targets[$target->fqcn()])) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * The keys as the property holds them, or null when the property cannot be checked or holds none of them.
     *
     * @param non-empty-list<int|string> $keys
     */
    private static function typed(array $keys, PropertyModel $property, ClassModel $model, Diagnostics $diagnostics): ?DiscriminatorValues
    {
        $declared = $property->type();
        $type = $declared instanceof NullableType ? $declared->inner() : $declared;
        $isInt = $type instanceof EnumType ? $type->backing()->value() === EnumBacking::INT : $type instanceof ScalarType && $type->kind() === 'int';
        if (!$type instanceof EnumType && !$type instanceof MixedType && (!$type instanceof ScalarType || !in_array($type->kind(), ['string', 'int'], true))) {
            $diagnostics->warning(
                sprintf(
                    'The constructor of %s does not check discriminator "%s": only a string, integer or enum property can be checked.',
                    $model->name()->fqcn(),
                    $property->wireName(),
                ),
                $model->source(),
            );

            return null;
        }

        $values = [];
        foreach ($keys as $key) {
            $value = $isInt ? (is_int($key) ? $key : null) : (string) $key;
            if ($value === null || !self::holds($type, $value)) {
                $diagnostics->warning(
                    sprintf('Discriminator value "%s" is not a value of property "%s", so it does not select %s.', $key, $property->wireName(), $model->name()->fqcn()),
                    $model->source(),
                );

                continue;
            }

            $values[] = $value;
        }

        if ($values === []) {
            return null;
        }

        // A check the type already makes would be dead code, which PHPStan reports in the generated class.
        return new DiscriminatorValues($property->name(), $values, $declared instanceof NullableType || !self::covers($type, $values));
    }

    /**
     * @param int|string $value
     */
    private static function holds(TypeModel $type, $value): bool
    {
        if ($type instanceof EnumType) {
            return $type->caseFor($value) !== null;
        }

        $refinement = $type instanceof ScalarType ? $type->phpDoc() : null;

        return $refinement === null || LiteralType::admits($refinement, $value) !== false;
    }

    /**
     * Whether the values are all the type admits.
     *
     * @param non-empty-list<int|string> $values
     */
    private static function covers(TypeModel $type, array $values): bool
    {
        $held = array_map('strval', $values);
        if ($type instanceof EnumType) {
            return array_diff(array_map('strval', array_keys($type->cases())), $held) === [];
        }

        $refinement = $type instanceof ScalarType ? $type->phpDoc() : null;
        if ($refinement === null || LiteralType::admits($refinement, $values[0]) === null) {
            return false;
        }

        $literals = array_map(static fn ($value): ?string => LiteralType::of($value), $values);

        return array_diff(explode('|', $refinement), $literals) === [];
    }
}
