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
            && !self::isComposed($schema);
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

    private static function mayBeObject(Schema $schema): bool
    {
        if ($schema->ref() !== null || $schema->extensions()->has('x-php-type')) {
            return false;
        }

        $types = $schema->nonNullTypes();

        return $types === [] || $types === [SchemaType::from(SchemaType::OBJECT)];
    }
}
