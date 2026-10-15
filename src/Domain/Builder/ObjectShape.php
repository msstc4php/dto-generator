<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Schema\Discriminator;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaType;
use MSSTC4PHP\DtoGenerator\Domain\Shared\DefaultValue;

/**
 * Whether every value one class schema admits, another admits too, read from the schemas alone: the wide one says
 * nothing but its properties and which it requires, and the narrow one has each of them, alike, requires them and
 * admits null only if the wide one does. Anything else the wide one says could forbid what the narrow one allows, so
 * it gives no answer. A `$ref` means the same schema only within one file.
 */
final class ObjectShape
{
    /** Keywords that tell about a schema without restricting its values. */
    private const ANNOTATIONS = ['title', 'examples', 'example', '$comment', 'externalDocs', 'xml'];

    private function __construct()
    {
    }

    public static function admits(Schema $wide, Schema $narrow): bool
    {
        if ($wide->location()->file() !== $narrow->location()->file() || !self::isPlainObject($narrow)
            || !self::saysOnlyProperties($wide) || ($narrow->isNullable() && !$wide->isNullable())
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

    private static function saysOnlyProperties(Schema $schema): bool
    {
        return self::isPlainObject($schema) && !$schema->discriminator() instanceof Discriminator
            && in_array($schema->additionalProperties(), [null, true], true) && $schema->extensions()->all() === []
            && array_diff($schema->keywordNames(), self::ANNOTATIONS) === []
            && $schema->enum() === null && !$schema->default() instanceof DefaultValue;
    }

    private static function isPlainObject(Schema $schema): bool
    {
        return $schema->ref() === null && !self::branches($schema)
            && array_filter($schema->nonNullTypes(), static fn (SchemaType $type): bool => $type->value() !== SchemaType::OBJECT) === [];
    }

    private static function branches(Schema $schema): bool
    {
        return $schema->allOf() !== [] || $schema->oneOf() !== [] || $schema->anyOf() !== [];
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
        if ($itemValues === null || in_array(SchemaType::OBJECT, $types, true) || $schema->propertyNames() !== []
            || $schema->additionalProperties() !== null || self::branches($schema) || $schema->discriminator() instanceof Discriminator) {
            return null;
        }

        sort($types);
        $default = $schema->default();

        // The order of an enum stays: it orders the cases of the generated enum.
        return serialize([
            $types,
            $schema->ref(),
            $schema->format(),
            $schema->enum(),
            $default instanceof DefaultValue ? $default->toJson() : null,
            self::sorted(array_diff_key($schema->keywords(), array_flip(self::ANNOTATIONS))),
            self::sorted($schema->extensions()->all()),
            $itemValues,
        ]);
    }

    /**
     * Objects with their keys in order, as JSON does not order them; lists keep theirs.
     *
     * @param array<array-key, mixed> $value
     *
     * @return array<array-key, mixed>
     */
    private static function sorted(array $value): array
    {
        $value = array_map(static fn ($item) => is_array($item) ? self::sorted($item) : $item, $value);
        if ($value !== array_values($value)) {
            ksort($value);
        }

        return $value;
    }
}
