<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Builder;

/**
 * An array whose `enum` lists scalars (Zoom, Vimeo) can hold none of them: the values are meant for its items, and go
 * there. Items given by a `$ref`, or with values of their own, keep the schema as it is.
 */
final class EnumOfItems
{
    private function __construct()
    {
    }

    /**
     * @param array<array-key, mixed> $node
     *
     * @return array<array-key, mixed>
     */
    public static function moved(array $node): array
    {
        $types = array_filter((array) ($node['type'] ?? []), 'is_string');
        $enum = $node['enum'] ?? null;
        $items = $node['items'] ?? [];
        if (array_values(array_diff($types, ['null'])) !== ['array'] || !is_array($enum) || !is_array($items)
            || array_intersect_key($items, array_flip(['$ref', 'enum', 'const'])) !== []) {
            return $node;
        }

        $values = array_values(array_filter($enum, static fn ($value): bool => $value !== null));
        $itemTypes = (array) ($items['type'] ?? []);
        $admitted = array_filter($values, static fn ($value): bool => self::admits($itemTypes, $value));
        // A null the type does not admit is a contradiction the parser reports as it is.
        if ($values === [] || count($admitted) !== count($values) || (count($values) !== count($enum) && !in_array('null', $types, true))) {
            return $node;
        }

        unset($node['enum']);
        $node['items'] = $items + ['enum' => $values];

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
