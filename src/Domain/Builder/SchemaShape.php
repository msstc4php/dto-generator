<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Schema\Discriminator;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaType;

final class SchemaShape
{
    private function __construct()
    {
    }

    /**
     * A list of values the builder turns into an enum; a reference, an explicit PHP type or a composition wins over it.
     */
    public static function isEnum(Schema $schema): bool
    {
        // Booleans or numbers alone cannot back a PHP enum; an object with properties stays a class.
        $values = array_filter($schema->enum() ?? [], static fn ($value): bool => is_int($value) || is_string($value));

        return $values !== []
            && !self::isClass($schema)
            && $schema->ref() === null
            && !$schema->extensions()->has('x-php-type')
            && !self::isComposed($schema)
            && !self::isMixedEnum($schema);
    }

    /**
     * Booleans alone, besides null, on a schema that admits booleans: a bool, without an enum to generate.
     */
    public static function isBooleanEnum(Schema $schema): bool
    {
        $values = array_filter($schema->enum() ?? [], static fn ($value): bool => $value !== null);
        $types = array_map(static fn (SchemaType $type): string => $type->value(), $schema->nonNullTypes());

        return $values !== [] && array_filter($values, 'is_bool') === $values && array_diff($types, [SchemaType::BOOLEAN]) === [];
    }

    /**
     * Strings and integers together: no PHP enum can back them, so the property takes a union of both.
     */
    public static function isMixedEnum(Schema $schema): bool
    {
        $values = array_filter($schema->enum() ?? [], static fn ($value): bool => $value !== null);
        $strings = array_filter($values, 'is_string');
        $ints = array_filter($values, 'is_int');

        return $strings !== [] && $ints !== [] && count($strings) + count($ints) === count($values);
    }

    /**
     * A schema the builder turns into a class (spec §5.3): an object with its own properties, an `allOf` of objects, or
     * a `oneOf`/`anyOf` with a discriminator. x-php-type maps it to an existing class instead.
     */
    public static function isClass(Schema $schema): bool
    {
        if (!self::mayBeObject($schema)) {
            return false;
        }

        if ($schema->allOf() !== []) {
            return !self::hasUnion($schema) && self::composesObjects($schema);
        }

        return self::isDiscriminated($schema) || $schema->propertyNames() !== [];
    }

    /**
     * A `oneOf`/`anyOf` whose variants a discriminator tells apart: an abstract base for them.
     */
    public static function isDiscriminated(Schema $schema): bool
    {
        return self::mayBeObject($schema)
            && $schema->allOf() === []
            && self::hasUnion($schema)
            && $schema->discriminator() instanceof Discriminator;
    }

    public static function discriminatorOf(Schema $schema): ?Discriminator
    {
        return self::isDiscriminated($schema) ? $schema->discriminator() : null;
    }

    /**
     * A schema that admits null alone; null beside a `$ref` or a composition makes that nullable instead.
     */
    public static function isNull(Schema $schema): bool
    {
        return $schema->ref() === null && $schema->types() !== [] && $schema->nonNullTypes() === [] && !self::isComposed($schema);
    }

    public static function isComposed(Schema $schema): bool
    {
        return $schema->allOf() !== [] || self::hasUnion($schema);
    }

    public static function hasUnion(Schema $schema): bool
    {
        return $schema->oneOf() !== [] || $schema->anyOf() !== [];
    }

    /**
     * A member that brings or constrains properties: a reference (assumed to be an object until the build resolves it),
     * an inline object, a `required` list or a nested composition of objects.
     */
    private static function isObjectMember(Schema $member): bool
    {
        if ($member->ref() !== null) {
            return true;
        }

        return $member->allOf() !== [] ? self::isClass($member) : $member->propertyNames() !== [] || $member->required() !== [];
    }

    /**
     * A lone `$ref` member only annotates the reference, so it takes two object members, an inline one or own properties.
     */
    private static function composesObjects(Schema $schema): bool
    {
        if ($schema->propertyNames() !== [] || $schema->required() !== []) {
            return true;
        }

        $members = array_values(array_filter($schema->allOf(), static fn (Schema $member): bool => self::isObjectMember($member)));

        return count($members) > 1 || (count($members) === 1 && $members[0]->ref() === null);
    }

    /**
     * Neither a reference nor mapped to an existing class by x-php-type, and typed as an object or not at all.
     */
    public static function mayBeObject(Schema $schema): bool
    {
        if ($schema->ref() !== null || $schema->extensions()->has('x-php-type')) {
            return false;
        }

        $types = $schema->nonNullTypes();

        return $types === [] || $types === [SchemaType::from(SchemaType::OBJECT)];
    }
}
