<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Schema;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Path;

/**
 * @api
 */
final class Reference
{
    private function __construct()
    {
    }

    /**
     * Resolves a `$ref` against the location it appears in; null for remote references.
     */
    public static function target(string $ref, SchemaLocation $from): ?SchemaLocation
    {
        if ($ref === '') {
            throw new InvalidModel('Empty $ref.');
        }

        if (preg_match('#^[A-Za-z][A-Za-z0-9+.\-]*://#', $ref) === 1) {
            return null;
        }

        $hash = strpos($ref, '#');
        $file = $hash === false ? $ref : (string) substr($ref, 0, $hash);
        $fragment = $hash === false ? '' : rawurldecode((string) substr($ref, $hash + 1));
        if ($fragment !== '' && strncmp($fragment, '/', 1) !== 0) {
            throw new InvalidModel(sprintf('$ref "%s": only JSON pointer fragments are supported, not anchors.', $ref));
        }

        JsonPointer::segments($fragment);
        $path = $file === '' ? $from->file() : Path::resolve(Path::directory($from->file()), rawurldecode($file));

        return new SchemaLocation($path, $fragment);
    }
}
