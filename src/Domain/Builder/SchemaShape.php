<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Schema\Discriminator;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaType;

final class SchemaShape
{
    /** Keywords an `allOf` member may have without restricting what the others admit: a member cannot widen them. */
    private const SAYS_NOTHING_IN_ALL_OF = [...ObjectShape::ANNOTATIONS, 'readOnly', 'writeOnly', 'nullable', 'deprecated', 'default'];

    private function __construct()
    {
    }

    /**
     * A list of values the builder turns into an enum; a reference, an explicit PHP type or a composition wins over it.
     */
    public static function isEnum(Schema $schema): bool
    {
        // Booleans or numbers alone cannot back a PHP enum; an object with properties stays a class.
        return self::backableValues($schema) !== []
            && !self::isClass($schema)
            && $schema->ref() === null
            && !$schema->extensions()->has('x-php-type')
            && !self::isComposed($schema)
            && !self::isMixedEnum($schema)
            && !self::contradictsItsEnum($schema);
    }

    /**
     * The string and integer values of the enum in order, duplicates included: what can back a PHP enum.
     *
     * @return list<int|string>
     */
    public static function backableValues(Schema $schema): array
    {
        return array_values(array_filter($schema->enum() ?? [], static fn ($value): bool => is_int($value) || is_string($value)));
    }

    /**
     * A `type` that admits none of the string or integer values of the enum (`type: array` with the values of its items):
     * the enum says nothing the type could hold, so it is ignored.
     */
    public static function contradictsItsEnum(Schema $schema): bool
    {
        $declared = array_map(static fn (SchemaType $type): string => $type->value(), $schema->nonNullTypes());
        $values = self::backableValues($schema);
        if ($declared === [] || $values === []) {
            return false;
        }

        $strings = in_array(SchemaType::STRING, $declared, true);
        $numbers = in_array(SchemaType::INTEGER, $declared, true) || in_array(SchemaType::NUMBER, $declared, true);
        foreach ($values as $value) {
            if (is_string($value) ? $strings : $numbers) {
                return false;
            }
        }

        return true;
    }

    /**
     * The values of an enum of booleans alone, besides null, on a schema that admits booleans: a bool, without an enum
     * to generate; null for any other schema.
     *
     * @return non-empty-list<bool>|null each value once
     */
    public static function booleanEnumValues(Schema $schema): ?array
    {
        $values = array_filter($schema->enum() ?? [], static fn ($value): bool => $value !== null);
        $types = array_map(static fn (SchemaType $type): string => $type->value(), $schema->nonNullTypes());
        $booleans = array_filter($values, 'is_bool');

        return $values !== [] && $booleans === $values && array_diff($types, [SchemaType::BOOLEAN]) === [] ? array_values(array_unique($booleans, SORT_REGULAR)) : null;
    }

    /**
     * Strings and integers together: no PHP enum can back them, so the property takes a union of both.
     */
    public static function isMixedEnum(Schema $schema): bool
    {
        $values = array_filter($schema->enum() ?? [], static fn ($value): bool => $value !== null);
        $strings = array_filter($values, 'is_string');
        $ints = array_filter($values, 'is_int');

        return $strings !== [] && $ints !== [] && count($strings) + count($ints) === count($values) && !self::contradictsItsEnum($schema);
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

        // A union beside an allOf is left to validation, unless a discriminator asks for variants.
        if ($schema->allOf() !== []) {
            return (!self::hasUnion($schema) || !$schema->discriminator() instanceof Discriminator) && self::composesObjects($schema);
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

    /**
     * An `allOf` member that only annotates, or only says "object": every value that passes the other members passes it.
     */
    public static function addsNothing(Schema $schema): bool
    {
        return self::isPlainObject($schema) || (
            $schema->types() === []
            && $schema->ref() === null
            && !$schema->extensions()->has('x-php-type')
            && $schema->propertyNames() === []
            && $schema->required() === []
            && !self::isComposed($schema)
            && $schema->enum() === null
            && array_diff($schema->keywordNames(), self::SAYS_NOTHING_IN_ALL_OF) === []
            && $schema->additionalProperties() === null
            && !$schema->items() instanceof Schema
            && !$schema->discriminator() instanceof Discriminator
        );
    }

    /**
     * `type: object` and nothing that types or constrains its members: any object passes.
     */
    public static function isPlainObject(Schema $schema): bool
    {
        $additional = $schema->additionalProperties();

        return $schema->ref() === null
            && !$schema->extensions()->has('x-php-type')
            && $schema->nonNullTypes() === [SchemaType::from(SchemaType::OBJECT)]
            && $schema->propertyNames() === []
            && $schema->required() === []
            && !self::isComposed($schema)
            && $schema->enum() === null
            && !$schema->hasKeyword('const')
            && !$schema->hasKeyword('patternProperties')
            && !$schema->discriminator() instanceof Discriminator
            && ($additional === null || $additional === true);
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
