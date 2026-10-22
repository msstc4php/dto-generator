<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Service\Schemas\Load;

use MSSTC4PHP\DtoGenerator\Application\Port\Document;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;

/**
 * The dialect each document's schemas are written in: OpenAPI 3.1 (or 3.2, alike), 3.0, whose `nullable` admits null,
 * or Swagger 2.0. A document read through a `$ref` is in the version it states, or else in that of the document that
 * refers to it first.
 *
 * @phpstan-import-type JsonValue from Json
 */
final class Versions
{
    public const OPENAPI_31 = 'OpenAPI 3.1';

    public const OPENAPI_30 = 'OpenAPI 3.0';

    public const SWAGGER_20 = 'Swagger 2.0';

    /** @var array<string, self::OPENAPI_*|self::SWAGGER_20> by document path */
    private array $dialects = [];

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
     * Whether the generator reads the version: OpenAPI 3.1 or 3.2, whose schemas are alike, or 3.0, which it rewrites
     * as 3.1.
     */
    public static function isUnderstood(string $version): bool
    {
        return self::isOpenApi30($version) || preg_match('/^3\.[12](?:\.\d+)?\z/', $version) === 1;
    }

    /**
     * A document with `swagger` and no `openapi`, whose schemas are its `definitions`.
     *
     * @param array<array-key, mixed> $root
     */
    public static function isSwagger(array $root): bool
    {
        return !array_key_exists('openapi', $root) && array_key_exists('swagger', $root);
    }

    /**
     * @return self::OPENAPI_*|self::SWAGGER_20
     */
    public function ofSource(Document $document): string
    {
        return $this->dialects[$document->path()] = $this->stated($document->root()) ?? self::OPENAPI_31;
    }

    /**
     * @return self::OPENAPI_*|self::SWAGGER_20
     */
    public function ofReferenced(Document $document, SchemaLocation $referredAt, Diagnostics $diagnostics): string
    {
        $path = $document->path();
        $stated = $this->stated($document->root());
        $referrer = $this->dialects[$referredAt->file()] ?? self::OPENAPI_31;
        if ($stated !== null || !isset($this->dialects[$path])) {
            return $this->dialects[$path] = $stated ?? $referrer;
        }

        if ($this->dialects[$path] !== $referrer && !in_array($path, $this->mixed, true)) {
            $this->mixed[] = $path;
            $diagnostics->warning(
                sprintf(
                    '%s states no "openapi" version and is read as %s, the version of the document that referred to it first, though %s, which refers to it here, is %s.',
                    $path,
                    $this->dialects[$path],
                    $referredAt->file(),
                    $referrer,
                ),
                $referredAt,
            );
        }

        return $this->dialects[$path];
    }

    /**
     * @param array<array-key, mixed> $root
     *
     * @return self::OPENAPI_*|self::SWAGGER_20|null
     */
    private function stated(array $root): ?string
    {
        if (self::isSwagger($root)) {
            return self::SWAGGER_20;
        }

        if (!array_key_exists('openapi', $root)) {
            return null;
        }

        return self::isOpenApi30(self::normalize(Json::value($root['openapi']))) ? self::OPENAPI_30 : self::OPENAPI_31;
    }
}
