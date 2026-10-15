<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Schema\Discriminator;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaType;
use MSSTC4PHP\DtoGenerator\Domain\Shared\DefaultValue;

/**
 * Whether every object one class schema admits, another admits too, read from the schemas alone: the wide one says
 * nothing but its properties and which it requires, and the narrow one has each of them, alike, and requires them.
 * Anything else the wide one says could forbid what the narrow one allows, so it gives no answer. A `$ref` means the
 * same schema only within one file.
 */
final class ObjectShape
{
    /** Keywords that tell about a schema without restricting its values. */
    private const ANNOTATIONS = ['title', 'examples', '$comment'];

    private function __construct()
    {
    }

    public static function admits(Schema $wide, Schema $narrow): bool
    {
        if ($wide->location()->file() !== $narrow->location()->file()
            || !self::isPlainObject($wide) || !self::isPlainObject($narrow) || $wide->discriminator() instanceof Discriminator
            || !in_array($wide->additionalProperties(), [null, true], true) || $wide->extensions()->all() !== []
            || array_diff($wide->keywordNames(), self::ANNOTATIONS) !== [] || $wide->enum() !== null || $wide->default() instanceof DefaultValue
            || array_diff($wide->required(), $narrow->required()) !== []) {
            return false;
        }

        foreach ($wide->propertyNames() as $name) {
            $mine = self::valuesOf($wide->requireProperty($name));
            $theirs = $narrow->property($name);
            if ($mine === null || !$theirs instanceof Schema || self::valuesOf($theirs) !== $mine) {
                return false;
            }
        }

        return true;
    }

    private static function isPlainObject(Schema $schema): bool
    {
        return $schema->ref() === null && $schema->allOf() === [] && $schema->oneOf() === [] && $schema->anyOf() === []
            && array_filter($schema->nonNullTypes(), static fn (SchemaType $type): bool => $type->value() !== SchemaType::OBJECT) === [];
    }

    /**
     * What decides the values of a property schema, for one that names them or lists their items; null for an object or
     * a schema of branches, whose class or union differs from one place to another.
     */
    private static function valuesOf(Schema $schema): ?string
    {
        $types = array_map(static fn (SchemaType $type): string => $type->value(), $schema->types());
        $items = $schema->items();
        $itemValues = $items instanceof Schema ? self::valuesOf($items) : '';
        if ($itemValues === null || in_array(SchemaType::OBJECT, $types, true) || $schema->propertyNames() !== [] || $schema->additionalProperties() !== null
            || $schema->allOf() !== [] || $schema->oneOf() !== [] || $schema->anyOf() !== [] || $schema->discriminator() instanceof Discriminator) {
            return null;
        }

        $default = $schema->default();

        return serialize([
            $types,
            $schema->ref(),
            $schema->format(),
            $schema->enum(),
            $default instanceof DefaultValue ? $default->toJson() : null,
            array_diff_key($schema->keywords(), array_flip(self::ANNOTATIONS)),
            $schema->extensions()->all(),
            $itemValues,
        ]);
    }
}
