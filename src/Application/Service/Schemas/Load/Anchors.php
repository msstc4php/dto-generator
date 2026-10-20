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

    /** Keys whose values map names to schemas or to OpenAPI objects: a name there is no keyword, whatever it is. */
    private const NAMES = [
        'properties', 'patternProperties', 'dependentSchemas', 'dependencies', '$defs', 'definitions', 'schemas', 'paths',
        'webhooks', 'responses', 'parameters', 'requestBodies', 'headers', 'content', 'encoding', 'callbacks', 'links',
        'pathItems', 'securitySchemes',
    ];

    /** Both name a plain anchor; a `$dynamicAnchor` names a dynamic one besides. */
    private const KEYWORDS = ['$anchor', '$dynamicAnchor'];

    /** @var array<string, list<string>> by anchor, the pointers of the schemas that declare it */
    private array $pointers = [];

    /**
     * @param array<array-key, mixed> $root as a Document holds it
     */
    public function __construct(array $root)
    {
        $this->collect($root, [], false);
    }

    /**
     * @return list<string> the pointers of the schemas declaring the anchor, each once, in document order
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
     * @param bool $names whether the keys of the node are names, not keywords
     */
    private function collect(array $node, array $segments, bool $names): void
    {
        $pointer = JsonPointer::fromSegments(...$segments);
        foreach ($names ? [] : self::KEYWORDS as $keyword) {
            $anchor = $node[$keyword] ?? null;
            if (is_string($anchor) && !in_array($pointer, $this->pointers[$anchor] ?? [], true)) {
                $this->pointers[$anchor][] = $pointer;
            }
        }

        foreach ($node as $key => $value) {
            $key = (string) $key;
            $data = !$names && (in_array($key, self::DATA, true) || Extensions::isExtensionKey($key));
            if (is_array($value) && !$data) {
                $this->collect($value, array_merge($segments, [$key]), !$names && in_array($key, self::NAMES, true));
            }
        }
    }
}
