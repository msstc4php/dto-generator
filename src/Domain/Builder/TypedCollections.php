<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ArgumentValue;
use MSSTC4PHP\DtoGenerator\Domain\Model\AttributeArgument;
use MSSTC4PHP\DtoGenerator\Domain\Model\AttributeModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassType;
use MSSTC4PHP\DtoGenerator\Domain\Model\CollectionType;
use MSSTC4PHP\DtoGenerator\Domain\Model\NullableType;

/**
 * `dto.collections.typedCollection`: a generated class listed by a property through the configured collection class asks
 * dto-collection-generator for that class, as `#[TypedCollection]` on itself.
 */
final class TypedCollections
{
    private const ATTRIBUTE = 'MSSTC4PHP\DtoCollection\Attribute\TypedCollection';

    private const VARIANT = 'MSSTC4PHP\DtoCollection\Attribute\Variant';

    private function __construct()
    {
    }

    /**
     * @param list<ClassModel> $models
     *
     * @return list<ClassModel> in the same order
     */
    public static function mark(array $models, ?Collections $collections): array
    {
        $variant = $collections instanceof Collections ? $collections->typedCollection() : null;
        if (!$collections instanceof Collections || $variant === null) {
            return $models;
        }

        $byItem = [];
        foreach ($models as $model) {
            foreach ($model->properties() as $property) {
                $type = $property->type();
                $type = $type instanceof NullableType ? $type->inner() : $type;
                $item = $type instanceof CollectionType ? $type->list()->item() : null;
                // x-php-collection may name a class of its own, which no generator is asked for.
                if ($item instanceof ClassType && $type->className()->fqcn() === self::configured($collections->collectionClass(), $item)) {
                    $byItem[$item->className()->fqcn()][$type->className()->fqcn()] = $type->className();
                }
            }
        }

        $marked = [];
        foreach ($models as $model) {
            $attributes = [];
            foreach ($byItem[$model->name()->fqcn()] ?? [] as $collection) {
                $attributes[] = self::attribute($collection, $variant);
            }

            $marked[] = $attributes === [] ? $model : $model->withAddedAttributes(...$attributes);
        }

        return $marked;
    }

    /**
     * The class the configuration gives the item; none when its short name makes no class name, which only a collection
     * x-php-collection chose can hold.
     */
    private static function configured(CollectionClass $class, ClassType $item): ?string
    {
        try {
            return $class->className($item->className()->shortName())->fqcn();
        } catch (InvalidModel $exception) {
            return null;
        }
    }

    /**
     * @param Collections::IMMUTABLE|Collections::MUTABLE $variant
     */
    private static function attribute(ClassName $collection, string $variant): AttributeModel
    {
        return new AttributeModel(ClassName::fromFqcn(self::ATTRIBUTE), [
            AttributeArgument::positional(ArgumentValue::literal($collection->fqcn())),
            AttributeArgument::positional(ArgumentValue::constant(ucfirst($variant), ClassName::fromFqcn(self::VARIANT))),
        ]);
    }
}
