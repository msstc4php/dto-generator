<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Builder;

/**
 * JSON Schema keywords that shape a value in ways no generated type expresses; silently dropping them would hide
 * that the DTO admits more than the schema. Keywords the Symfony bridge turns into constraints are not listed.
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

        return in_array($keyword, self::NO_EFFECT, true) ? sprintf('"%s" is not supported and has no effect on the generated type.', $keyword) : null;
    }
}
