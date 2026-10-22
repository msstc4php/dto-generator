<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Builder;

/**
 * Rewrites a schema node of a Swagger 2.0 document as OpenAPI 3.0 writes it, which {@see OpenApi30} then rewrites as
 * 3.1: a discriminator names its property alone, `x-nullable` stands for `nullable`, and `file` is binary content.
 */
final class Swagger20
{
    private function __construct()
    {
    }

    /**
     * @param array<array-key, mixed> $node as JsonValue holds it
     *
     * @return array<array-key, mixed>
     */
    public static function asOpenApi30(array $node): array
    {
        $discriminator = $node['discriminator'] ?? null;
        if (is_string($discriminator)) {
            $node['discriminator'] = ['propertyName' => $discriminator];
        }

        if (($node['x-nullable'] ?? null) === true && !array_key_exists('nullable', $node)) {
            $node['nullable'] = true;
        }

        if (($node['type'] ?? null) === 'file') {
            $node['type'] = 'string';
            $node['format'] ??= 'binary';
        }

        return $node;
    }
}
