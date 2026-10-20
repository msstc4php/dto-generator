<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaType;

/**
 * Rewrites a schema node of an OpenAPI 3.0 document as OpenAPI 3.1 writes it, so that everything after the parser,
 * extensions included, reads one dialect.
 */
final class OpenApi30
{
    /** Each 3.0 boolean bound with the bound it makes exclusive. */
    private const EXCLUSIVE = ['exclusiveMinimum' => 'minimum', 'exclusiveMaximum' => 'maximum'];

    private Diagnostics $diagnostics;

    /** Whether x-php-type or x-php-skip replaces the schema, so a keyword without effect is no news. */
    private bool $quiet;

    private function __construct(Diagnostics $diagnostics, bool $quiet)
    {
        $this->diagnostics = $diagnostics;
        $this->quiet = $quiet;
    }

    /**
     * Rewrites the node itself and the inline schema of its `not`, the one subschema 3.0 has that stays raw JSON; the
     * parser reaches the other subschemas of 3.0 on its own. Keywords 3.0 does not have are left as they are.
     *
     * @param array<array-key, mixed> $node as JsonValue holds it
     *
     * @return array<array-key, mixed>
     */
    public static function asOpenApi31(array $node, SchemaLocation $location, Diagnostics $diagnostics, bool $quiet): array
    {
        return (new self($diagnostics, $quiet))->rewrite($node, $location);
    }

    /**
     * @param array<array-key, mixed> $node
     *
     * @return array<array-key, mixed>
     */
    private function rewrite(array $node, SchemaLocation $location): array
    {
        $node = $this->withNullAdmitted($node, $location);
        foreach (self::EXCLUSIVE as $exclusive => $bound) {
            $node = $this->withExclusiveBound($node, $exclusive, $bound, $location);
        }

        $not = $node['not'] ?? null;
        if (is_array($not)) {
            $node['not'] = $this->rewrite($not, $location->child('not'));
        }

        return $node;
    }

    /**
     * `nullable: true` as a type with null. Beside a `$ref` or a composition without a type, a null type makes that
     * nullable; a schema with no type at all admits null anyway.
     *
     * @param array<array-key, mixed> $node
     *
     * @return array<array-key, mixed>
     */
    private function withNullAdmitted(array $node, SchemaLocation $location): array
    {
        if (!array_key_exists('nullable', $node)) {
            return $node;
        }

        $nullable = $node['nullable'];
        unset($node['nullable']);
        if (!is_bool($nullable)) {
            $this->diagnostics->error('"nullable" must be a boolean.', $location->child('nullable'));
        }

        if ($nullable !== true) {
            return $node;
        }

        $type = $node['type'] ?? null;
        if (is_string($type) && SchemaType::tryFrom($type) instanceof SchemaType) {
            $node['type'] = [$type, SchemaType::NULL];
        } elseif (is_array($type)) {
            $node['type'] = in_array(SchemaType::NULL, $type, true) ? $type : array_merge($type, [SchemaType::NULL]);
        } elseif ($type === null && array_intersect(['$ref', 'allOf', 'oneOf', 'anyOf'], array_keys($node)) !== []) {
            // A member of the union instead would break a discriminated union and the distribution of an allOf over it.
            $node['type'] = [SchemaType::NULL];
        }

        return $node;
    }

    /**
     * `exclusiveMinimum: true` beside `minimum: 5` as `exclusiveMinimum: 5`. A number is kept as 3.1 reads it, as
     * documents that mix the versions mean.
     *
     * @param array<array-key, mixed> $node
     *
     * @return array<array-key, mixed>
     */
    private function withExclusiveBound(array $node, string $exclusive, string $bound, SchemaLocation $location): array
    {
        if (!array_key_exists($exclusive, $node)) {
            return $node;
        }

        $flag = $node[$exclusive];
        if (is_int($flag) || is_float($flag)) {
            $this->notice(sprintf('"%s" is a boolean in OpenAPI 3.0; the number is read as in 3.1.', $exclusive), $location->child($exclusive));

            return $node;
        }

        $value = $node[$bound] ?? null;
        if ($flag === true && (is_int($value) || is_float($value))) {
            // In place: the parser keeps keywords in the document's order, which diagnostics and the constraints
            // extensions generate from them follow.
            $node[$exclusive] = $value;
            unset($node[$bound]);

            return $node;
        }

        unset($node[$exclusive]);
        if (!is_bool($flag)) {
            $this->diagnostics->error(sprintf('"%s" must be a boolean.', $exclusive), $location->child($exclusive));
        } elseif ($flag) {
            $this->notice(sprintf('"%s: true" has no effect without a numeric "%s".', $exclusive, $bound), $location->child($exclusive));
        }

        return $node;
    }

    private function notice(string $message, SchemaLocation $at): void
    {
        if (!$this->quiet) {
            $this->diagnostics->warning($message, $at);
        }
    }
}
