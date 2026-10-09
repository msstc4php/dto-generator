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
    public const DATE = ['date-time', 'date'];

    private const STRING = [
        'email', 'idn-email', 'uri', 'uri-reference', 'iri', 'iri-reference', 'uri-template', 'uuid', 'hostname',
        'idn-hostname', 'ipv4', 'ipv6', 'time', 'duration', 'byte', 'binary', 'password', 'regex', 'json-pointer',
        'relative-json-pointer', 'date-time', 'date',
    ];

    private const KNOWN = [
        'string' => [self::STRING, 'string', 'a string'],
        'int' => [['int32', 'int64'], 'integer', 'an int'],
        'float' => [['float', 'double'], 'number', 'a float'],
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
        if ($format === null || !isset(self::KNOWN[$kind]) || in_array($format, self::KNOWN[$kind][0], true)) {
            return;
        }

        $diagnostics->warning(
            sprintf('Unknown %s format "%s"; the property stays %s.', self::KNOWN[$kind][1], $format, self::KNOWN[$kind][2]),
            $schema->location()->child('format'),
        );
    }
}
