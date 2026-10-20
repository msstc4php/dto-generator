<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Service\Schemas\Load;

use MSSTC4PHP\DtoGenerator\Application\Port\Document;
use MSSTC4PHP\DtoGenerator\Domain\Builder\SchemaParser;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;

/**
 * Which documents are OpenAPI 3.0, whose schemas the parser reads with `nullable`. A document read through a `$ref`
 * is in the version it states, or else in that of the document referring to it.
 *
 * @phpstan-import-type JsonValue from Json
 */
final class Versions
{
    /** @var array<string, bool> by document path */
    private array $openApi30 = [];

    /**
     * @param JsonValue $version
     */
    public static function isOpenApi30($version): bool
    {
        // Unquoted `openapi: 3.0` arrives from YAML as a float.
        if (is_float($version)) {
            $version = Json::floatToString($version);
        }

        return is_string($version) && preg_match('/^3\.0(?:\.\d+)?\z/', $version) === 1;
    }

    public function parserFor(Document $document, string $referredFrom, SchemaParser $parser): SchemaParser
    {
        $path = $document->path();
        if (!isset($this->openApi30[$path])) {
            $root = $document->root();
            $this->openApi30[$path] = array_key_exists('openapi', $root) ? self::isOpenApi30(Json::value($root['openapi'])) : ($this->openApi30[$referredFrom] ?? false);
        }

        return $this->openApi30[$path] ? $parser->forOpenApi30() : $parser;
    }
}
