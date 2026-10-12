<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Builder;

/**
 * JSON Schema keywords that shape a value in ways no generated type expresses; silently dropping them would hide
 * that the DTO admits more than the schema. An extension that checks one claims it, and the parser stays quiet.
 */
final class UnsupportedKeywords
{
    private const NO_EFFECT = [
        'prefixItems', 'patternProperties', 'if', 'then', 'else', 'not', 'dependentSchemas', 'dependentRequired',
        'unevaluatedProperties', 'unevaluatedItems', 'contains', 'minContains', 'maxContains', 'propertyNames',
        'additionalItems', 'dependencies', '$dynamicRef',
    ];

    private function __construct()
    {
    }

    /**
     * Whether an extension may take over the warning about the keyword because it checks it.
     */
    public static function isClaimable(string $keyword): bool
    {
        return in_array($keyword, self::NO_EFFECT, true);
    }

    /**
     * @param mixed $value
     */
    public static function warning(string $keyword, $value): ?string
    {
        if ($keyword === 'nullable') {
            if ($value !== true) {
                return null;
            }

            return '"nullable" is OpenAPI 3.0 and has no effect in 3.1; write type: [T, \'null\'].';
        }

        return in_array($keyword, self::NO_EFFECT, true) ? self::unchecked($keyword) : null;
    }

    /**
     * The warning about a keyword nothing checks: none supports it, or the extension that claims it left it alone.
     */
    public static function unchecked(string $keyword): string
    {
        return sprintf('"%s" is not supported and has no effect on the generated type.', $keyword);
    }
}
