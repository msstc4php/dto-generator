<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;

/**
 * The core x-php-* / x-dto-* vocabulary (spec §7) and where each key takes effect; other x- keys belong to
 * enrichers and are left alone.
 */
final class ExtensionVocabulary
{
    /** x-php-attributes and x-php-all-of take effect in later stages. */
    public const KNOWN = [
        'x-php-class-name', 'x-php-name', 'x-php-type', 'x-dto-mutable', 'x-php-all-of', 'x-php-skip', 'x-php-attributes',
    ];

    /** Object schemas, and enums and compositions, which become classes in a later stage. */
    public const CLASS_SCHEMA = ['x-php-class-name', 'x-php-type', 'x-dto-mutable', 'x-php-all-of', 'x-php-skip', 'x-php-attributes'];

    /** Named non-object schemas, which are inlined wherever they are referenced. */
    public const ALIAS_SCHEMA = ['x-php-type', 'x-php-skip'];

    public const PROPERTY = ['x-php-name', 'x-php-type', 'x-php-skip', 'x-php-attributes'];

    public const ITEMS = ['x-php-type'];

    private function __construct()
    {
    }

    /**
     * @param list<string> $allowed
     */
    public static function check(Schema $schema, array $allowed, Diagnostics $diagnostics): void
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
