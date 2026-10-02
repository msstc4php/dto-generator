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
    /** x-php-attributes and x-php-all-of take effect in later stages. */
    private const KNOWN = [
        'x-php-class-name', 'x-php-name', 'x-php-type', 'x-dto-mutable', 'x-php-all-of', 'x-php-skip', 'x-php-attributes',
        'x-enum-descriptions',
    ];

    private const CLASS_SCHEMA = [
        'x-php-class-name', 'x-php-type', 'x-dto-mutable', 'x-php-all-of', 'x-php-skip', 'x-php-attributes', 'x-enum-descriptions',
    ];

    private const ALIAS_SCHEMA = ['x-php-type', 'x-php-skip'];

    private const PROPERTY = ['x-php-name', 'x-php-type', 'x-php-skip', 'x-php-attributes'];

    /** An inline object or enum becomes a class or an enum, so it takes the keys of one. */
    private const DECLARATION = ['x-php-class-name', 'x-dto-mutable', 'x-enum-descriptions'];

    private const ITEMS = ['x-php-type'];

    private function __construct()
    {
    }

    /**
     * An object schema, or an enum or composition, which becomes a class in a later stage.
     */
    public static function checkClass(Schema $schema, Diagnostics $diagnostics): void
    {
        self::check($schema, self::CLASS_SCHEMA, $diagnostics);
    }

    /**
     * A named non-object schema, inlined wherever it is referenced.
     */
    public static function checkAlias(Schema $schema, Diagnostics $diagnostics): void
    {
        self::check($schema, self::ALIAS_SCHEMA, $diagnostics);
    }

    public static function checkProperty(Schema $schema, Diagnostics $diagnostics): void
    {
        self::check($schema, self::PROPERTY, $diagnostics);
    }

    /**
     * @param list<string> $allowed
     */
    private static function check(Schema $schema, array $allowed, Diagnostics $diagnostics): void
    {
        self::checkKeys($schema, self::withDeclaration($schema, $allowed), $diagnostics);
        self::checkValues($schema, $diagnostics);
    }

    /**
     * The `items` and `additionalProperties` schemas, down to any depth.
     */
    private static function checkValues(Schema $schema, Diagnostics $diagnostics): void
    {
        foreach ([$schema->items(), $schema->additionalProperties()] as $value) {
            if ($value instanceof Schema) {
                self::checkKeys($value, self::withDeclaration($value, self::ITEMS), $diagnostics);
                self::checkValues($value, $diagnostics);
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
     */
    private static function checkKeys(Schema $schema, array $allowed, Diagnostics $diagnostics): void
    {
        foreach ($schema->extensions()->keys() as $key) {
            // x-enum-descriptions belongs to the vocabulary without the x-php-/x-dto- prefix that marks the rest.
            if (strncmp($key, 'x-php-', 6) !== 0 && strncmp($key, 'x-dto-', 6) !== 0 && !in_array($key, self::KNOWN, true)) {
                continue;
            }

            $at = $schema->location()->child($key);
            if (!in_array($key, self::KNOWN, true)) {
                $diagnostics->error(sprintf('Unknown extension "%s"; known: %s.', $key, implode(', ', self::KNOWN)), $at);
            } elseif (!in_array($key, $allowed, true)) {
                $diagnostics->warning(sprintf('"%s" has no effect here.', $key), $at);
            }
        }
    }
}
