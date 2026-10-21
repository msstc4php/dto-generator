<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Service\Schemas\Load;

use MSSTC4PHP\DtoGenerator\Application\Config\OperationFilter;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;

/**
 * The schemas of a document's request bodies and responses, named as openapi-generator names them:
 * `<operationId> Request` and `<operationId> <status> Response`, `<method> <path>` standing in for a missing
 * operationId; a request body or response of `components` is `<name> Request` or `<name> Response`.
 *
 * @phpstan-import-type JsonValue from Json
 */
final class Operations
{
    private const METHODS = ['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace'];

    private const COMPONENTS = ['requestBodies' => 'Request', 'responses' => 'Response'];

    private SchemaLocation $document;

    private OperationFilter $filter;

    private Diagnostics $diagnostics;

    /** @var list<array{string, JsonValue, SchemaLocation}> */
    private array $schemas = [];

    private function __construct(string $path, OperationFilter $filter, Diagnostics $diagnostics)
    {
        $this->document = new SchemaLocation($path);
        $this->filter = $filter;
        $this->diagnostics = $diagnostics;
    }

    /**
     * Only the schemas written there: one behind a `$ref` alone has a name of its own.
     *
     * @param array<array-key, mixed> $root as a Document holds it
     *
     * @return list<array{string, JsonValue, SchemaLocation}> name, node and location, in document order
     */
    public static function of(string $path, array $root, OperationFilter $filter, Diagnostics $diagnostics): array
    {
        $operations = new self($path, $filter, $diagnostics);
        foreach (self::objects(Json::value($root['paths'] ?? null)) as $pattern => $item) {
            $operations->pathItem((string) $pattern, $item);
        }

        $components = self::objects(Json::value($root['components'] ?? null));
        foreach (self::COMPONENTS as $kind => $suffix) {
            foreach (self::objects(Json::value($components[$kind] ?? null)) as $name => $object) {
                if (!$filter->selects((string) $name)) {
                    continue;
                }

                $operations->body($object, $name . ' ' . $suffix, $operations->document->child('components', $kind, (string) $name));
            }
        }

        return $operations->schemas;
    }

    /**
     * @param array<array-key, mixed> $item
     */
    private function pathItem(string $pattern, array $item): void
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
            $name = is_string($id) && trim($id) !== '' ? $id : $method . ' ' . $pattern;
            if (!$this->filter->selects($name)) {
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
            if (!$this->namesComponent($ref)) {
                $this->diagnostics->warning(sprintf('Only a request body or response of this document\'s components is read behind "$ref"; %s gives no class.', $ref), $at->child('$ref'));
            }

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
        if (is_array($schema) && array_keys($schema) === ['$ref']) {
            return;
        }

        $this->schemas[] = [$name, Json::value($schema), $at->child('content', $media, 'schema')];
    }

    private function namesComponent(string $ref): bool
    {
        foreach (array_keys(self::COMPONENTS) as $kind) {
            if (strpos($ref, '#/components/' . $kind . '/') === 0) {
                return true;
            }
        }

        return false;
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
