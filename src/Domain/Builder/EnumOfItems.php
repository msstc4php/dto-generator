<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;

/**
 * An array whose `enum` lists scalars (Zoom, Vimeo) can hold none of them: the values are meant for its items, and go
 * there, with the `x-enum-*` keys that name and describe them. Items given by a `$ref`, or with values of their own,
 * keep the schema as it is.
 */
final class EnumOfItems
{
    private function __construct()
    {
    }

    /**
     * @param array<array-key, mixed> $node
     * @param bool $nullableKeyword whether `nullable: true` admits null (OpenAPI 3.0, Swagger 2.0 as rewritten)
     *
     * @return array<array-key, mixed>
     */
    public static function moved(array $node, bool $nullableKeyword = false): array
    {
        $types = array_filter((array) ($node['type'] ?? []), 'is_string');
        $enum = $node['enum'] ?? null;
        $items = $node['items'] ?? [];
        if (array_values(array_diff($types, ['null'])) !== ['array'] || !is_array($enum) || !is_array($items) || ($items !== [] && Json::isList($items))
            || array_intersect_key($items, array_flip(['$ref', 'enum', 'const'])) !== []) {
            return $node;
        }

        $values = array_values(array_filter($enum, static fn ($value): bool => $value !== null));
        $itemTypes = (array) ($items['type'] ?? []);
        $admitted = array_filter($values, static fn ($value): bool => self::admits($itemTypes, $value));
        // A null the type does not admit is a contradiction the parser reports as it is; OpenAPI 3.0 admits it with
        // `nullable`, which is read after this. Tuple items (a list) are left to the parser.
        $nullable = in_array('null', $types, true) || ($nullableKeyword && ($node['nullable'] ?? null) === true);
        if ($values === [] || count($admitted) !== count($values) || (count($values) !== count($enum) && !$nullable)) {
            return $node;
        }

        unset($node['enum']);
        $items += ['enum' => $values];
        // The names and descriptions of the cases go with them.
        foreach ($node as $key => $value) {
            if (is_string($key) && strncmp($key, 'x-enum-', 7) === 0) {
                $items += [$key => $value];
                unset($node[$key]);
            }
        }

        $node['items'] = $items;

        return $node;
    }

    /**
     * Whether items of these types, any when none is given, may hold the scalar.
     *
     * @param array<array-key, mixed> $types
     * @param mixed $value
     */
    private static function admits(array $types, $value): bool
    {
        $kinds = ['string' => ['string'], 'integer' => ['integer', 'number'], 'double' => ['number'], 'boolean' => ['boolean']];
        $kind = $kinds[gettype($value)] ?? null;

        return $kind !== null && ($types === [] || array_filter($kind, static fn (string $name): bool => in_array($name, $types, true)) !== []);
    }
}
