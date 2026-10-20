<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\KeywordChecks;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Discriminator;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Extensions;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaBuilder;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaType;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;

/**
 * Turns a decoded JSON Schema node into a {@see Schema}. A malformed keyword becomes a located diagnostic
 * and is dropped, so one pass reports every problem.
 *
 * @phpstan-import-type JsonValue from Json
 */
final class SchemaParser
{
    /** Inside a schema whose type x-php-type or x-php-skip replaces. */
    private bool $quiet = false;

    /** The keywords extensions check, whose occurrences wait for them rather than giving a warning here. */
    private ?KeywordChecks $checks = null;

    /** OpenAPI 3.0 reads `nullable: true` as admitting null, which 3.1 writes as a type. */
    private bool $openApi30 = false;

    public function claiming(KeywordChecks $checks): self
    {
        $parser = clone $this;
        $parser->checks = $checks;

        return $parser;
    }

    /**
     * The parser of a document in OpenAPI 3.0, where `nullable: true` admits null.
     */
    public function forOpenApi30(): self
    {
        $parser = clone $this;
        $parser->openApi30 = true;

        return $parser;
    }

    /**
     * @param JsonValue $node
     */
    public function parse($node, SchemaLocation $location, Diagnostics $diagnostics): Schema
    {
        $builder = new SchemaBuilder($location);
        if ($node === true) {
            return $builder->build();
        }

        if (!is_array($node) || ($node !== [] && Json::isList($node))) {
            $diagnostics->error($node === false ? 'The "false" schema is not supported.' : 'A schema must be an object.', $location);

            return $builder->build();
        }

        // x-php-type or x-php-skip replaces what the schema says, so neither its keywords nor those of its subschemas
        // have an effect to warn about.
        $outer = $this->quiet;
        $this->quiet = $outer || is_string($node['x-php-type'] ?? null) || ($node['x-php-skip'] ?? null) === true;

        try {
            return $this->parseObject($this->openApi30 ? $this->withNullAdmitted($node, $location, $diagnostics) : $node, $builder, $location, $diagnostics);
        } finally {
            $this->quiet = $outer;
        }
    }

    /**
     * @param array<array-key, mixed> $node
     */
    private function parseObject(array $node, SchemaBuilder $builder, SchemaLocation $location, Diagnostics $diagnostics): Schema
    {
        $extensions = [];
        foreach ($node as $key => $rawValue) {
            $keyword = (string) $key;
            $value = Json::value($rawValue);
            if (Extensions::isExtensionKey($keyword)) {
                $extensions[$keyword] = $value;

                continue;
            }

            if (in_array($keyword, Schema::STRUCTURAL_KEYWORDS, true)) {
                $this->applyStructural($builder, $keyword, $value, $location->child($keyword), $diagnostics);

                continue;
            }

            $unsupported = $this->quiet || ($this->openApi30 && $keyword === 'nullable') ? null : UnsupportedKeywords::warning($keyword, $value);
            if ($unsupported !== null && $this->checks instanceof KeywordChecks && $this->checks->isClaimed($keyword)) {
                $this->checks->expect($location, $keyword);
                $unsupported = null;
            }

            if ($unsupported !== null) {
                $diagnostics->warning($unsupported, $location->child($keyword));
            }

            $builder->keyword($keyword, $value);
        }

        return $builder->extensions(new Extensions($extensions))->build();
    }

    /**
     * The node with `nullable: true` written as OpenAPI 3.1 does: null joins the type. Beside a `$ref` or a composition
     * without a type, a null type makes that nullable; a schema with no type at all admits null anyway.
     *
     * @param array<array-key, mixed> $node as JsonValue holds it
     *
     * @return array<array-key, mixed>
     */
    private function withNullAdmitted(array $node, SchemaLocation $location, Diagnostics $diagnostics): array
    {
        $nullable = array_key_exists('nullable', $node) ? $node['nullable'] : false;
        if (!is_bool($nullable)) {
            $diagnostics->error('"nullable" must be a boolean.', $location->child('nullable'));
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
     * @param JsonValue $value
     */
    private function applyStructural(SchemaBuilder $builder, string $keyword, $value, SchemaLocation $at, Diagnostics $diagnostics): void
    {
        switch ($keyword) {
            case 'type':
                $builder->types(...$this->types($value, $at, $diagnostics));

                break;
            case '$ref':
                if (is_string($value) && $value !== '') {
                    $builder->ref($value);
                } else {
                    $diagnostics->error('"$ref" must be a non-empty string.', $at);
                }

                break;
            case 'format':
                if (is_string($value) && $value !== '') {
                    $builder->format($value);
                } else {
                    $diagnostics->error('"format" must be a non-empty string.', $at);
                }

                break;
            case 'description':
                if (is_string($value)) {
                    $builder->description($value);
                } else {
                    $diagnostics->error('"description" must be a string.', $at);
                }

                break;
            case 'deprecated':
                if (is_bool($value)) {
                    $builder->deprecated($value);
                } else {
                    $diagnostics->error('"deprecated" must be a boolean.', $at);
                }

                break;
            case 'default':
                $builder->default($value);

                break;
            case 'enum':
                if (is_array($value) && $value !== [] && Json::isList($value)) {
                    $builder->enum(array_map(static fn ($item) => Json::value($item), $value));
                } else {
                    $diagnostics->error('"enum" must be a non-empty list.', $at);
                }

                break;
            case 'properties':
                $this->properties($builder, $value, $at, $diagnostics);

                break;
            case 'required':
                $builder->required(...$this->required($value, $at, $diagnostics));

                break;
            case 'items':
                if (is_array($value) && $value !== [] && Json::isList($value)) {
                    $diagnostics->error('"items" must be a single schema; tuple arrays ("prefixItems") are not supported.', $at);
                } else {
                    $builder->items($this->parse($value, $at, $diagnostics));
                }

                break;
            case 'additionalProperties':
                if (is_bool($value)) {
                    $builder->additionalProperties($value);
                } elseif (is_array($value) && ($value === [] || !Json::isList($value))) {
                    $builder->additionalProperties($this->parse($value, $at, $diagnostics));
                } else {
                    $diagnostics->error('"additionalProperties" must be a boolean or a schema.', $at);
                }

                break;
            case 'allOf':
                $builder->allOf(...$this->schemaList($value, $keyword, $at, $diagnostics));

                break;
            case 'oneOf':
                $builder->oneOf(...$this->schemaList($value, $keyword, $at, $diagnostics));

                break;
            case 'anyOf':
                $builder->anyOf(...$this->schemaList($value, $keyword, $at, $diagnostics));

                break;
            case 'discriminator':
                $discriminator = $this->discriminator($value, $at, $diagnostics);
                if ($discriminator instanceof Discriminator) {
                    $builder->discriminator($discriminator);
                }

                break;
        }
    }

    /**
     * @param JsonValue $value
     *
     * @return list<SchemaType>
     */
    private function types($value, SchemaLocation $at, Diagnostics $diagnostics): array
    {
        $names = is_string($value) ? [$value] : (is_array($value) && Json::isList($value) ? $value : []);
        if ($names === []) {
            $diagnostics->error('"type" must be a type name or a non-empty list of type names.', $at);
        }

        $types = [];
        foreach ($names as $index => $name) {
            $type = is_string($name) ? SchemaType::tryFrom($name) : null;
            if (!$type instanceof SchemaType) {
                $diagnostics->error(
                    $name === null
                        ? 'Unknown type null; quote it as "null" (unquoted, YAML reads it as a missing value).'
                        : sprintf('Unknown type %s.', is_string($name) ? '"' . $name . '"' : gettype($name)),
                    is_string($value) ? $at : $at->child((string) $index),
                );

                continue;
            }

            if (in_array($type, $types, true)) {
                $diagnostics->warning(sprintf('Type "%s" is listed twice.', $type->value()), $at);

                continue;
            }

            $types[] = $type;
        }

        return $types;
    }

    /**
     * @param JsonValue $value
     */
    private function properties(SchemaBuilder $builder, $value, SchemaLocation $at, Diagnostics $diagnostics): void
    {
        if (!is_array($value) || ($value !== [] && Json::isList($value))) {
            $diagnostics->error('"properties" must be an object.', $at);

            return;
        }

        foreach ($value as $name => $node) {
            $builder->property((string) $name, $this->parse(Json::value($node), $at->child((string) $name), $diagnostics));
        }
    }

    /**
     * @param JsonValue $value
     *
     * @return list<string>
     */
    private function required($value, SchemaLocation $at, Diagnostics $diagnostics): array
    {
        if (!is_array($value) || !Json::isList($value)) {
            $diagnostics->error('"required" must be a list of property names.', $at);

            return [];
        }

        $names = [];
        foreach ($value as $index => $name) {
            // Unquoted numeric names arrive from YAML as integers.
            if (is_int($name)) {
                $name = (string) $name;
            }

            if (!is_string($name)) {
                $diagnostics->error('A required property name must be a string.', $at->child((string) $index));

                continue;
            }

            if (in_array($name, $names, true)) {
                $diagnostics->warning(sprintf('"%s" is listed twice.', $name), $at->child((string) $index));

                continue;
            }

            $names[] = $name;
        }

        return $names;
    }

    /**
     * @param JsonValue $value
     *
     * @return list<Schema>
     */
    private function schemaList($value, string $keyword, SchemaLocation $at, Diagnostics $diagnostics): array
    {
        if (!is_array($value) || $value === [] || !Json::isList($value)) {
            $diagnostics->error(sprintf('"%s" must be a non-empty list of schemas.', $keyword), $at);

            return [];
        }

        $schemas = [];
        foreach ($value as $index => $node) {
            $schemas[] = $this->parse(Json::value($node), $at->child((string) $index), $diagnostics);
        }

        return $schemas;
    }

    /**
     * @param JsonValue $value
     */
    private function discriminator($value, SchemaLocation $at, Diagnostics $diagnostics): ?Discriminator
    {
        $propertyName = is_array($value) ? ($value['propertyName'] ?? null) : null;
        if (!is_array($value) || !is_string($propertyName) || $propertyName === '') {
            $diagnostics->error('"discriminator" needs a non-empty "propertyName".', $at);

            return null;
        }

        $rawMapping = $value['mapping'] ?? [];
        if (!is_array($rawMapping) || ($rawMapping !== [] && Json::isList($rawMapping))) {
            $diagnostics->error('"mapping" must be an object.', $at->child('mapping'));

            return null;
        }

        $mapping = [];
        foreach ($rawMapping as $discriminatorValue => $ref) {
            if (!is_string($ref) || $ref === '') {
                $diagnostics->error('A mapping target must be a non-empty string.', $at->child('mapping', (string) $discriminatorValue));

                return null;
            }

            $mapping[$discriminatorValue] = $ref;
        }

        return new Discriminator($propertyName, $mapping);
    }
}
