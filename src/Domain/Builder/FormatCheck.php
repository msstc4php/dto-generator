<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;

/**
 * The `format` values the generator knows per scalar kind; any other gives a warning and the plain type.
 */
final class FormatCheck
{
    /** @var list<non-empty-string> */
    public const DATE = ['date-time', 'date'];

    /** @var list<non-empty-string> */
    private const STRING = [
        'email', 'idn-email', 'uri', 'uri-reference', 'iri', 'iri-reference', 'uri-template', 'uuid', 'hostname',
        'idn-hostname', 'ipv4', 'ipv6', 'time', 'duration', 'byte', 'binary', 'password', 'regex', 'json-pointer',
        'relative-json-pointer', 'date-time', 'date',
    ];

    /** @var array<'string'|'int'|'float', array{formats: list<non-empty-string>, type: non-empty-string, result: non-empty-string}> */
    private const KNOWN = [
        'string' => ['formats' => self::STRING, 'type' => 'string', 'result' => 'a string'],
        'int' => ['formats' => ['int32', 'int64'], 'type' => 'integer', 'result' => 'an int'],
        'float' => ['formats' => ['float', 'double'], 'type' => 'number', 'result' => 'a float'],
    ];

    private function __construct()
    {
    }

    /**
     * @param 'string'|'int'|'float'|'bool' $kind the scalar the property becomes; a bool takes no format
     */
    public static function check(Schema $schema, string $kind, Diagnostics $diagnostics): void
    {
        $format = $schema->format();
        $known = self::KNOWN[$kind] ?? null;
        if ($format === null || $known === null || in_array($format, $known['formats'], true)) {
            return;
        }

        $diagnostics->warning(
            sprintf('Unknown %s format "%s"; the property stays %s.', $known['type'], $format, $known['result']),
            $schema->location()->child('format'),
        );
    }
}
