<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Service\Schemas\Load;

use MSSTC4PHP\DtoGenerator\Domain\Schema\Extensions;
use MSSTC4PHP\DtoGenerator\Domain\Schema\JsonPointer;

/**
 * The schemas of a document that a `$anchor` names, for `$ref: '#Name'`. The whole document is one scope: a `$id`
 * inside it starts no scope of its own.
 */
final class Anchors
{
    /** Keywords whose values are data, which an object with "$anchor" in them does not make a schema. */
    private const DATA = ['const', 'default', 'enum', 'example', 'examples'];

    /** @var array<string, list<string>> by anchor, the pointers of the schemas that declare it */
    private array $pointers = [];

    /**
     * @param array<array-key, mixed> $root as a Document holds it
     */
    public function __construct(array $root)
    {
        $this->collect($root, []);
    }

    /**
     * @return list<string> the pointers of the schemas declaring the anchor, in document order
     */
    public function pointersOf(string $anchor): array
    {
        return $this->pointers[$anchor] ?? [];
    }

    /**
     * Whether a fragment is an anchor name as JSON Schema 2020-12 writes it.
     */
    public static function isName(string $fragment): bool
    {
        return preg_match('/^[A-Za-z_][-A-Za-z0-9._]*\z/', $fragment) === 1;
    }

    /**
     * @param array<array-key, mixed> $node
     * @param list<string> $segments
     */
    private function collect(array $node, array $segments): void
    {
        $anchor = $node['$anchor'] ?? null;
        if (is_string($anchor)) {
            $this->pointers[$anchor][] = JsonPointer::fromSegments(...$segments);
        }

        foreach ($node as $key => $value) {
            $key = (string) $key;
            if (is_array($value) && !in_array($key, self::DATA, true) && !Extensions::isExtensionKey($key)) {
                $this->collect($value, array_merge($segments, [$key]));
            }
        }
    }
}
