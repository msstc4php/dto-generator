<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;

/**
 * The core x-php-* / x-dto-* vocabulary (spec §7) and where each key takes effect; other x- keys belong to
 * enrichers and are left alone. Every check also covers the schema's nested `items`.
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
        self::checkKeys($schema, $allowed, $diagnostics);
        for ($items = $schema->items(); $items instanceof Schema; $items = $items->items()) {
            self::checkKeys($items, self::ITEMS, $diagnostics);
        }
    }

    /**
     * @param list<string> $allowed
     */
    private static function checkKeys(Schema $schema, array $allowed, Diagnostics $diagnostics): void
    {
        foreach ($schema->extensions()->keys() as $key) {
            if (strncmp($key, 'x-php-', 6) !== 0 && strncmp($key, 'x-dto-', 6) !== 0) {
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
