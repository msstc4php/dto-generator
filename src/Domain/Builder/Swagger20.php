<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;
use MSSTC4PHP\DtoGenerator\Domain\Schema\JsonPointer;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;

/**
 * Rewrites a schema node of a Swagger 2.0 document as OpenAPI 3.0 writes it, which {@see OpenApi30} then rewrites as
 * 3.1: a discriminator names its property alone, `x-nullable` stands for `nullable`, and `file` is binary content.
 *
 * @phpstan-import-type JsonValue from Json
 */
final class Swagger20
{
    private function __construct()
    {
    }

    /**
     * @param array<array-key, mixed> $node as JsonValue holds it
     * @param bool $quiet whether x-php-type or x-php-skip replaces the schema, so a keyword without effect is no news
     *
     * @return array<array-key, mixed>
     */
    public static function asOpenApi30(array $node, SchemaLocation $location, Diagnostics $diagnostics, bool $quiet): array
    {
        $discriminator = $node['discriminator'] ?? null;
        if (is_string($discriminator)) {
            $node['discriminator'] = ['propertyName' => $discriminator];
        }

        $nullable = $node['x-nullable'] ?? null;
        if ($nullable === true && !array_key_exists('nullable', $node)) {
            $node['nullable'] = true;
        } elseif ($nullable !== null && !is_bool($nullable) && !$quiet) {
            $diagnostics->warning('"x-nullable" must be a boolean; it has no effect.', $location->child('x-nullable'));
        }

        if (($node['type'] ?? null) === 'file') {
            $node['type'] = 'string';
            $node['format'] ??= 'binary';
        }

        return $node;
    }

    /**
     * The definitions with the `x-discriminator-value` of each that extends another through allOf in the parent's
     * discriminator mapping, as swagger-codegen reads it; the name of a definition is its value otherwise.
     *
     * @param list<array{string, JsonValue}> $definitions name and node, as the document lists them
     *
     * @return list<array{string, JsonValue}>
     */
    public static function withDiscriminatorValues(array $definitions): array
    {
        $at = [];
        foreach ($definitions as $index => [$name]) {
            $at[$name] = $index;
        }

        foreach ($definitions as [$name, $node]) {
            $value = is_array($node) ? $node['x-discriminator-value'] ?? null : null;
            if (!is_string($value) && !is_int($value)) {
                continue;
            }

            foreach (self::parents($node) as $parent) {
                $index = $at[$parent] ?? null;
                $parentNode = $index === null ? null : $definitions[$index][1];
                $discriminator = is_array($parentNode) ? $parentNode['discriminator'] ?? null : null;
                $discriminator = is_string($discriminator) ? ['propertyName' => $discriminator] : $discriminator;
                if ($index === null || !is_array($parentNode) || !is_array($discriminator)) {
                    continue;
                }

                $mapping = $discriminator['mapping'] ?? [];
                if (is_array($mapping) && !array_key_exists((string) $value, $mapping)) {
                    $mapping[(string) $value] = '#' . JsonPointer::fromSegments('definitions', $name);
                    $discriminator['mapping'] = $mapping;
                    $parentNode['discriminator'] = $discriminator;
                    $definitions[$index][1] = $parentNode;
                }
            }
        }

        return $definitions;
    }

    /**
     * @param JsonValue $node
     *
     * @return list<string> the definitions of this document its allOf names
     */
    private static function parents($node): array
    {
        $parents = [];
        foreach (is_array($node) && is_array($node['allOf'] ?? null) ? $node['allOf'] : [] as $member) {
            $ref = is_array($member) ? $member['$ref'] ?? null : null;
            if (!is_string($ref) || strncmp($ref, '#/', 2) !== 0) {
                continue;
            }

            try {
                $segments = JsonPointer::segments(substr($ref, 1));
            } catch (InvalidModel $exception) {
                continue;
            }

            if (count($segments) === 2 && $segments[0] === 'definitions') {
                $parents[] = $segments[1];
            }
        }

        return $parents;
    }
}
