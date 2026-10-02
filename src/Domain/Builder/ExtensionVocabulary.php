<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;

/**
 * The core x-php-* / x-dto-* vocabulary (spec §7) and where each key takes effect; other x- keys belong to
 * enrichers and are left alone. Every check also covers the schema's nested `items` and `additionalProperties`.
 */
final class ExtensionVocabulary
{
    /** The core keys; any other key starting with x-php- or x-dto- is a typo. */
    public const KNOWN = [
        'x-php-class-name', 'x-php-name', 'x-php-type', 'x-dto-mutable', 'x-php-all-of', 'x-php-skip', 'x-php-attributes',
        'x-enum-descriptions',
    ];

    private const CLASS_SCHEMA = [
        'x-php-class-name', 'x-php-type', 'x-dto-mutable', 'x-php-all-of', 'x-php-skip', 'x-php-attributes', 'x-enum-descriptions',
    ];

    /** An enum is no class, so it carries no attributes. */
    private const ENUM_SCHEMA = ['x-php-class-name', 'x-php-type', 'x-dto-mutable', 'x-php-all-of', 'x-php-skip', 'x-enum-descriptions'];

    private const ALIAS_SCHEMA = ['x-php-type', 'x-php-skip'];

    private const PROPERTY = ['x-php-name', 'x-php-type', 'x-php-skip', 'x-php-attributes'];

    /** An inline object or enum becomes a class or an enum, so it takes the keys of one. */
    private const DECLARATION = ['x-php-class-name', 'x-dto-mutable', 'x-enum-descriptions'];

    private const ITEMS = ['x-php-type'];

    private function __construct()
    {
    }

    /**
     * A schema that becomes a class; the inline members of its `allOf` only contribute properties.
     *
     * @param list<string> $aliases keys of `attributeAliases`, which a class takes like x-php-attributes
     */
    public static function checkClass(Schema $schema, Diagnostics $diagnostics, array $aliases = []): void
    {
        self::check($schema, array_merge(self::CLASS_SCHEMA, $aliases), $diagnostics, $aliases);
        self::checkMembers($schema, $diagnostics, $aliases);
    }

    /**
     * A schema that becomes an enum, which carries no attributes.
     *
     * @param list<string> $aliases
     */
    public static function checkEnum(Schema $schema, Diagnostics $diagnostics, array $aliases = []): void
    {
        self::check($schema, self::ENUM_SCHEMA, $diagnostics, $aliases);
    }

    /**
     * A named non-object schema, inlined wherever it is referenced.
     *
     * @param list<string> $aliases
     */
    public static function checkAlias(Schema $schema, Diagnostics $diagnostics, array $aliases = []): void
    {
        self::check($schema, self::ALIAS_SCHEMA, $diagnostics, $aliases);
    }

    /**
     * @param list<string> $aliases keys of `attributeAliases`, which a property takes like x-php-attributes
     */
    public static function checkProperty(Schema $schema, Diagnostics $diagnostics, array $aliases = []): void
    {
        self::check($schema, array_merge(self::PROPERTY, $aliases), $diagnostics, $aliases);
    }

    /**
     * @param list<string> $aliases
     */
    private static function checkMembers(Schema $schema, Diagnostics $diagnostics, array $aliases): void
    {
        foreach ($schema->allOf() as $member) {
            if ($member->ref() === null) {
                self::checkKeys($member, [], $diagnostics, $aliases);
                self::checkValues($member, $diagnostics, $aliases);
                self::checkMembers($member, $diagnostics, $aliases);
            }
        }
    }

    /**
     * @param list<string> $allowed
     * @param list<string> $aliases
     */
    private static function check(Schema $schema, array $allowed, Diagnostics $diagnostics, array $aliases): void
    {
        self::checkKeys($schema, self::withDeclaration($schema, $allowed), $diagnostics, $aliases);
        self::checkValues($schema, $diagnostics, $aliases);
    }

    /**
     * The `items` and `additionalProperties` schemas, down to any depth.
     *
     * @param list<string> $aliases
     */
    private static function checkValues(Schema $schema, Diagnostics $diagnostics, array $aliases): void
    {
        foreach ([$schema->items(), $schema->additionalProperties()] as $value) {
            if ($value instanceof Schema) {
                self::checkKeys($value, self::withDeclaration($value, self::ITEMS), $diagnostics, $aliases);
                self::checkValues($value, $diagnostics, $aliases);
            }
        }
    }

    /**
     * @param list<string> $allowed
     *
     * @return list<string>
     */
    private static function withDeclaration(Schema $schema, array $allowed): array
    {
        return SchemaShape::isClass($schema) || SchemaShape::isEnum($schema) ? array_merge($allowed, self::DECLARATION) : $allowed;
    }

    /**
     * @param list<string> $allowed
     * @param list<string> $aliases
     */
    private static function checkKeys(Schema $schema, array $allowed, Diagnostics $diagnostics, array $aliases): void
    {
        $known = array_merge(self::KNOWN, $aliases);
        foreach ($schema->extensions()->keys() as $key) {
            // x-enum-descriptions and the aliases belong to the vocabulary without the x-php-/x-dto- prefix of the rest.
            if (strncmp($key, 'x-php-', 6) !== 0 && strncmp($key, 'x-dto-', 6) !== 0 && !in_array($key, $known, true)) {
                continue;
            }

            $at = $schema->location()->child($key);
            if (!in_array($key, $known, true)) {
                $diagnostics->error(sprintf('Unknown extension "%s"; known: %s.', $key, implode(', ', self::KNOWN)), $at);
            } elseif (!in_array($key, $allowed, true)) {
                $diagnostics->warning(sprintf('"%s" has no effect here.', $key), $at);
            }
        }
    }
}
