<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassType;
use MSSTC4PHP\DtoGenerator\Domain\Model\CollectionType;
use MSSTC4PHP\DtoGenerator\Domain\Model\EnumBacking;
use MSSTC4PHP\DtoGenerator\Domain\Model\EnumType;
use MSSTC4PHP\DtoGenerator\Domain\Model\ListType;
use MSSTC4PHP\DtoGenerator\Domain\Model\MixedType;
use MSSTC4PHP\DtoGenerator\Domain\Model\NullableType;
use MSSTC4PHP\DtoGenerator\Domain\Model\ScalarType;
use MSSTC4PHP\DtoGenerator\Domain\Model\TypeModel;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;
use MSSTC4PHP\DtoGenerator\Domain\Target\Capability;
use MSSTC4PHP\DtoGenerator\Domain\Target\TargetProfile;

/**
 * Which property holds its list in a collection object: `dto.collections` for every list, `x-php-collection` on the
 * property or a schema it wraps for one.
 */
final class PropertyCollections
{
    private TypeMapper $types;

    private TargetProfile $target;

    private ?Collections $collections;

    public function __construct(TypeMapper $types, TargetProfile $target, ?Collections $collections)
    {
        $this->types = $types;
        $this->target = $target;
        $this->collections = $collections;
    }

    public static function isCollection(TypeModel $type): bool
    {
        return ($type instanceof NullableType ? $type->inner() : $type) instanceof CollectionType;
    }

    /**
     * Whether the schema keeps its list an array by `x-php-collection: false`.
     */
    public function declines(Schema $schema): bool
    {
        $carrier = $this->types->collectionCarrier($schema);

        return $carrier instanceof Schema && $carrier->extensions()->get('x-php-collection') === false;
    }

    /**
     * The type with its list held by a collection object, when one is asked for.
     */
    public function hold(Schema $schema, TypeModel $type, Diagnostics $diagnostics): TypeModel
    {
        $list = $type instanceof NullableType ? $type->inner() : $type;
        $list = $list instanceof ListType ? $list : null;

        $carrier = $this->types->collectionCarrier($schema);
        $class = $this->collections instanceof Collections ? $this->collections->collectionClass() : null;
        if ($carrier instanceof Schema) {
            $at = $carrier->location()->child('x-php-collection');
            if (!$list instanceof ListType) {
                $diagnostics->warning('"x-php-collection" applies to a list only; this property stays as it is.', $at);

                return $type;
            }

            $value = $carrier->extensions()->get('x-php-collection');
            if ($value === false) {
                return $type;
            }

            if (!is_string($value)) {
                $diagnostics->error('"x-php-collection" must be a class name or false.', $at);

                return $type;
            }

            try {
                $class = $class instanceof CollectionClass ? $class->withPattern($value) : CollectionClass::fromPattern($value);
            } catch (InvalidModel $exception) {
                $diagnostics->error($exception->getMessage(), $at);

                return $type;
            }
        }

        if (!$class instanceof CollectionClass || !$list instanceof ListType) {
            return $type;
        }

        $item = $this->itemName($list->item());
        // An untyped list says nothing of its items, as an allOf member that only adds minItems; it stays quietly.
        if ($item === null && $class->hasItem() && $list->item() instanceof MixedType) {
            return $type;
        }

        if ($item === null && $class->hasItem()) {
            $diagnostics->warning(
                sprintf('This list stays an array: {item} in %s stands for one class, enum or scalar item type, not %s.', $class->pattern(), $list->item()->describe()),
                $schema->location(),
            );

            return $type;
        }

        try {
            $collection = $class->wrap($list, $item ?? '');
        } catch (InvalidModel $exception) {
            $diagnostics->error($exception->getMessage(), $carrier instanceof Schema ? $carrier->location()->child('x-php-collection') : $schema->location());

            return $type;
        }

        return $type instanceof NullableType ? NullableType::of($collection) : $collection;
    }

    /**
     * What `{item}` stands for: the short name of a class or enum, or the scalar in full words.
     */
    private function itemName(TypeModel $item): ?string
    {
        if ($item instanceof EnumType && !$this->target->supports(Capability::from(Capability::ENUMS))) {
            $item = $item->backing()->value() === EnumBacking::INT ? ScalarType::int() : ScalarType::string();
        }

        if ($item instanceof ClassType || $item instanceof EnumType) {
            return $item->className()->shortName();
        }

        return $item instanceof ScalarType ? ['int' => 'Integer', 'float' => 'Float', 'string' => 'String', 'bool' => 'Boolean'][$item->kind()] : null;
    }
}
