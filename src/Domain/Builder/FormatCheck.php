<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Formats;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;

/**
 * A `format` the generator does not know for the scalar gives a warning and the plain type.
 */
final class FormatCheck
{
    /** @var array<'string'|'int'|'float', array{type: 'string'|'integer'|'number', result: non-empty-string}> */
    private const KINDS = [
        'string' => ['type' => 'string', 'result' => 'a string'],
        'int' => ['type' => 'integer', 'result' => 'an int'],
        'float' => ['type' => 'number', 'result' => 'a float'],
    ];

    private function __construct()
    {
    }

    /**
     * @param 'string'|'int'|'float'|'bool' $kind the scalar the property becomes; a bool takes no format
     * @param FormatMap $project the formats the config knows
     */
    public static function check(Schema $schema, string $kind, FormatMap $project, Diagnostics $diagnostics): void
    {
        $format = $schema->normalizedFormat();
        $known = self::KINDS[$kind] ?? null;
        if ($format === null || $known === null || Formats::isKnown($format, $known['type']) || $project->knows($schema, $kind)) {
            return;
        }

        $diagnostics->warning(
            sprintf(
                'Unknown %s format "%s"; the property stays %s. "formats" in the config can map it to a type.',
                $known['type'],
                (string) $schema->format(),
                $known['result'],
            ),
            $schema->location()->child('format'),
        );
    }
}
