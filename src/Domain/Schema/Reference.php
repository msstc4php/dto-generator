<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Schema;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Path;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Url;

/**
 * @api
 */
final class Reference
{
    private function __construct()
    {
    }

    /**
     * Resolves a `$ref` against the location it appears in: a file path against the directory of a local document, a
     * URL as it stands, and any reference in a remote document against its URL.
     *
     * @throws InvalidModel for a malformed reference or a URL that cannot be fetched
     */
    public static function target(string $ref, SchemaLocation $from): SchemaLocation
    {
        if ($ref === '') {
            throw new InvalidModel('Empty $ref.');
        }

        $hash = strpos($ref, '#');
        $file = $hash === false ? $ref : (string) substr($ref, 0, $hash);
        $fragment = $hash === false ? '' : rawurldecode((string) substr($ref, $hash + 1));
        if ($fragment !== '' && strncmp($fragment, '/', 1) !== 0) {
            throw new InvalidModel(sprintf('$ref "%s": only JSON pointer fragments are supported, not anchors.', $ref));
        }

        JsonPointer::segments($fragment);
        if ($file === '') {
            return new SchemaLocation($from->file(), $fragment);
        }

        // A URL keeps its percent-encoding: it is how the server names the document.
        if (Url::isUrl($file) || Url::isUrl($from->file())) {
            return new SchemaLocation(Url::isUrl($file) ? Url::normalize($file) : Url::resolve($from->file(), $file), $fragment);
        }

        return new SchemaLocation(Path::resolve(Path::directory($from->file()), rawurldecode($file)), $fragment);
    }
}
