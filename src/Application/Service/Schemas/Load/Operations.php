<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Service\Schemas\Load;

use MSSTC4PHP\DtoGenerator\Application\Config\OperationFilter;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;
use MSSTC4PHP\DtoGenerator\Domain\Schema\JsonPointer;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Reference;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;

/**
 * The schemas of the request bodies and responses of a document's operations, named as openapi-generator names them:
 * `<operationId> Request` and `<operationId> <status> Response`, `<method> <path>` standing in for a missing
 * operationId. A body behind a `$ref` into `components` of the document is read there, once, as `<name> Request` or
 * `<name> Response`.
 *
 * @phpstan-import-type JsonValue from Json
 */
final class Operations
{
    private const METHODS = ['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace'];

    private const COMPONENTS = ['requestBodies' => 'Request', 'responses' => 'Response'];

    /** Keywords that only describe: a `$ref`, or an allOf of one, with only these beside it says what the `$ref` says. */
    private const ANNOTATIONS = ['title', 'description', 'example', 'examples', 'deprecated', 'externalDocs', '$comment', 'nullable', 'readOnly', 'writeOnly'];

    private SchemaLocation $document;

    /** @var array<array-key, mixed> */
    private array $root;

    private Diagnostics $diagnostics;

    /** @var list<array{string, JsonValue, SchemaLocation}> */
    private array $schemas = [];

    /** @var list<string> pointers of the components already read */
    private array $components = [];

    /**
     * @param array<array-key, mixed> $root
     */
    private function __construct(string $path, array $root, Diagnostics $diagnostics)
    {
        $this->document = new SchemaLocation($path);
        $this->root = $root;
        $this->diagnostics = $diagnostics;
    }

    /**
     * Only the schemas written there: one that only names another (a `$ref` alone, or in an allOf with nothing but
     * annotations) gives no class, as the other has one.
     *
     * @param array<array-key, mixed> $root as a Document holds it
     *
     * @return list<array{string, JsonValue, SchemaLocation}> name, node and location, in document order
     */
    public static function of(string $path, array $root, OperationFilter $filter, Diagnostics $diagnostics): array
    {
        $operations = new self($path, $root, $diagnostics);
        foreach (self::objects(Json::value($root['paths'] ?? null)) as $pattern => $item) {
            $operations->pathItem((string) $pattern, $item, $filter);
        }

        return $operations->schemas;
    }

    /**
     * @param array<array-key, mixed> $item
     */
    private function pathItem(string $pattern, array $item, OperationFilter $filter): void
    {
        $at = $this->document->child('paths', $pattern);
        if (isset($item['$ref'])) {
            $this->diagnostics->warning('A path item behind "$ref" is not read; its operations give no classes.', $at->child('$ref'));

            return;
        }

        foreach (self::METHODS as $method) {
            $operation = $item[$method] ?? null;
            if (!is_array($operation)) {
                continue;
            }

            $id = $operation['operationId'] ?? null;
            $id = is_string($id) ? trim($id) : '';
            $name = $id !== '' ? $id : $method . ' ' . $pattern;
            if (!$filter->selects($name)) {
                continue;
            }

            $requestBody = Json::value($operation['requestBody'] ?? null);
            if (is_array($requestBody)) {
                $this->body($requestBody, $name . ' Request', $at->child($method, 'requestBody'));
            }

            foreach (self::objects(Json::value($operation['responses'] ?? null)) as $status => $response) {
                $this->body($response, $name . ' ' . $status . ' Response', $at->child($method, 'responses', (string) $status));
            }
        }
    }

    /**
     * A JSON media type, else the first that has a schema; the others mostly say the same in another encoding.
     *
     * @param array<array-key, mixed> $body a request body or response
     */
    private function body(array $body, string $name, SchemaLocation $at): void
    {
        $ref = $body['$ref'] ?? null;
        if (is_string($ref)) {
            $this->component($ref, $at);

            return;
        }

        $chosen = null;
        foreach (self::objects(Json::value($body['content'] ?? null)) as $media => $content) {
            $media = (string) $media;
            if (!array_key_exists('schema', $content)) {
                continue;
            }

            if ($this->isJson($media)) {
                $chosen = [$media, $content['schema']];

                break;
            }

            $chosen ??= [$media, $content['schema']];
        }

        if ($chosen === null) {
            return;
        }

        [$media, $schema] = $chosen;
        $schema = Json::value($schema);
        if (!$this->onlyNamesAnother($schema)) {
            $this->schemas[] = [$name, $schema, $at->child('content', $media, 'schema')];
        }
    }

    /**
     * Reads the request body or response of `components` a `$ref` names, unless it was read already.
     */
    private function component(string $ref, SchemaLocation $at): void
    {
        $target = null;
        try {
            $target = Reference::target($ref, $at);
        } catch (InvalidModel $exception) {
            // Reported below as any reference that names no component.
        }

        $segments = $target instanceof SchemaLocation && $target->file() === $this->document->file() ? JsonPointer::segments($target->pointer()) : [];
        $suffix = count($segments) === 3 && $segments[0] === 'components' ? self::COMPONENTS[$segments[1]] ?? null : null;
        if ($suffix === null) {
            $this->diagnostics->warning(sprintf('Only a request body or response of this document\'s components is read behind "$ref"; %s gives no class.', $ref), $at->child('$ref'));

            return;
        }

        [, $kind, $name] = $segments;
        $pointer = JsonPointer::fromSegments('components', $kind, $name);
        // Several operations name one, and one may name itself.
        if (in_array($pointer, $this->components, true)) {
            return;
        }

        $this->components[] = $pointer;
        $component = JsonPointer::has($this->root, $pointer) ? Json::value(JsonPointer::get($this->root, $pointer)) : null;
        if (!is_array($component) || ($component !== [] && Json::isList($component))) {
            $this->diagnostics->warning(sprintf('"$ref" %s names no request body or response; it gives no class.', $ref), $at->child('$ref'));

            return;
        }

        $this->body($component, $name . ' ' . $suffix, $this->document->child('components', $kind, $name));
    }

    /**
     * @param JsonValue $schema
     */
    private function onlyNamesAnother($schema): bool
    {
        if (!is_array($schema)) {
            return false;
        }

        $members = $schema['allOf'] ?? null;
        $named = is_array($members) && count($members) === 1 && is_array($members[0] ?? null) && array_keys($members[0]) === ['$ref'];
        if (isset($schema['$ref']) === $named) {
            return false;
        }

        foreach (array_keys($schema) as $keyword) {
            if (!in_array($keyword, ['$ref', 'allOf'], true) && !$this->annotates((string) $keyword)) {
                return false;
            }
        }

        return true;
    }

    private function annotates(string $keyword): bool
    {
        return in_array($keyword, self::ANNOTATIONS, true) || strpos($keyword, 'x-') === 0;
    }

    private function isJson(string $media): bool
    {
        $type = strtolower(trim(explode(';', $media)[0]));

        return $type === 'application/json' || substr($type, -5) === '+json';
    }

    /**
     * @param JsonValue $value
     *
     * @return array<array-key, array<array-key, mixed>> the members that are objects
     */
    private static function objects($value): array
    {
        return is_array($value) ? array_filter($value, 'is_array') : [];
    }
}
