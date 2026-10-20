<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Service\Schemas\Load;

use MSSTC4PHP\DtoGenerator\Application\Port\Document;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;

/**
 * Which documents are OpenAPI 3.0, whose `nullable` admits null. A document read through a `$ref` is in the version it
 * states, or else in that of the document that refers to it first.
 *
 * @phpstan-import-type JsonValue from Json
 */
final class Versions
{
    /** @var array<string, bool> by document path */
    private array $openApi30 = [];

    /** @var list<string> documents without a version read from both versions, reported once */
    private array $mixed = [];

    /**
     * @param JsonValue $version
     */
    public static function normalize($version): ?string
    {
        // Unquoted `openapi: 3.0` arrives from YAML as a float.
        if (is_float($version)) {
            return Json::floatToString($version);
        }

        return is_string($version) ? $version : null;
    }

    public static function isOpenApi30(?string $version): bool
    {
        return $version !== null && preg_match('/^3\.0(?:\.\d+)?\z/', $version) === 1;
    }

    /**
     * Whether the generator reads the version: OpenAPI 3.1, or 3.0, which it rewrites as 3.1.
     */
    public static function isUnderstood(string $version): bool
    {
        return self::isOpenApi30($version) || preg_match('/^3\.1(?:\.\d+)?\z/', $version) === 1;
    }

    public function ofSource(Document $document): bool
    {
        return $this->openApi30[$document->path()] = self::isOpenApi30($this->stated($document));
    }

    public function ofReferenced(Document $document, SchemaLocation $referredAt, Diagnostics $diagnostics): bool
    {
        $path = $document->path();
        $stated = $this->stated($document);
        $referrer = $this->openApi30[$referredAt->file()] ?? false;
        if ($stated !== null || !isset($this->openApi30[$path])) {
            return $this->openApi30[$path] = $stated !== null ? self::isOpenApi30($stated) : $referrer;
        }

        if ($this->openApi30[$path] !== $referrer && !in_array($path, $this->mixed, true)) {
            $this->mixed[] = $path;
            $diagnostics->warning(
                sprintf(
                    '%s states no "openapi" version and is read as OpenAPI %s, the version of the document that referred to it first, though %s, which refers to it here, is %s.',
                    $path,
                    $this->openApi30[$path] ? '3.0' : '3.1',
                    $referredAt->file(),
                    $referrer ? '3.0' : '3.1',
                ),
                $referredAt,
            );
        }

        return $this->openApi30[$path];
    }

    private function stated(Document $document): ?string
    {
        $root = $document->root();

        return array_key_exists('openapi', $root) ? self::normalize(Json::value($root['openapi'])) : null;
    }
}
