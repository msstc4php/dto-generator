<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassType;
use MSSTC4PHP\DtoGenerator\Domain\Model\ListType;
use MSSTC4PHP\DtoGenerator\Domain\Model\MapType;
use MSSTC4PHP\DtoGenerator\Domain\Model\MixedType;
use MSSTC4PHP\DtoGenerator\Domain\Model\NullableType;
use MSSTC4PHP\DtoGenerator\Domain\Model\ScalarType;
use MSSTC4PHP\DtoGenerator\Domain\Model\TypeModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\UnionType;
use MSSTC4PHP\DtoGenerator\Domain\Schema\ReferenceUse;
use MSSTC4PHP\DtoGenerator\Domain\Schema\ResolvedSchema;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaGraph;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaType;
use MSSTC4PHP\DtoGenerator\Domain\Target\Capability;
use MSSTC4PHP\DtoGenerator\Domain\Target\TargetProfile;

/**
 * Maps a schema to a PHP type (spec §5.1). Named non-object schemas are aliases and are inlined.
 */
final class TypeMapper
{
    private const STRING_FORMATS = [
        'email', 'idn-email', 'uri', 'uri-reference', 'iri', 'iri-reference', 'uri-template', 'uuid', 'hostname',
        'idn-hostname', 'ipv4', 'ipv6', 'time', 'duration', 'byte', 'binary', 'password', 'regex', 'json-pointer',
        'relative-json-pointer',
    ];

    private const DATE_FORMATS = ['date-time', 'date'];

    private const INTEGER_FORMATS = ['int32', 'int64'];

    private const NUMBER_FORMATS = ['float', 'double'];

    private SchemaGraph $graph;

    private Declarations $declarations;

    private TargetProfile $target;

    /** @var array<int|string, ClassName> */
    private array $formats;

    /**
     * @param array<int|string, ClassName> $formats custom formats from the config
     */
    public function __construct(SchemaGraph $graph, Declarations $declarations, TargetProfile $target, array $formats)
    {
        $this->graph = $graph;
        $this->declarations = $declarations;
        $this->target = $target;
        $this->formats = $formats;
    }

    public function map(Schema $schema, Diagnostics $diagnostics): TypeModel
    {
        return $this->mapWithin($schema, $diagnostics, []);
    }

    public static function nullable(TypeModel $type): TypeModel
    {
        return $type instanceof MixedType || $type instanceof NullableType ? $type : new NullableType($type);
    }

    /**
     * @param array<string, true> $aliases alias schemas being inlined, to stop $ref loops
     */
    private function mapWithin(Schema $schema, Diagnostics $diagnostics, array $aliases): TypeModel
    {
        $type = $this->bareType($schema, $diagnostics, $aliases);

        return $this->admitsNull($schema) ? self::nullable($type) : $type;
    }

    /**
     * @param array<string, true> $aliases
     */
    private function bareType(Schema $schema, Diagnostics $diagnostics, array $aliases): TypeModel
    {
        if ($schema->extensions()->has('x-php-type')) {
            return $this->explicitType($schema, $diagnostics);
        }

        $key = $schema->location()->toString();
        if ($this->declarations->isAbandoned($key)) {
            return new MixedType();
        }

        $declared = $this->declared($key);
        if ($declared instanceof TypeModel) {
            return $declared;
        }

        $unsupported = SchemaShape::unsupportedKeyword($schema);
        if ($unsupported !== null) {
            $diagnostics->error(
                sprintf('"%s" is not supported yet; enums, composition and inline objects arrive in a later version.', $unsupported),
                $schema->location(),
            );

            return new MixedType();
        }

        $ref = $schema->ref();
        if ($ref !== null) {
            return $this->reference($schema, $ref, $diagnostics, $aliases);
        }

        if (SchemaShape::isClass($schema)) {
            $diagnostics->error(
                'This inline object is not generated (only properties of generated classes get one); move it to components/schemas and use $ref.',
                $schema->location(),
            );

            return new MixedType();
        }

        if (SchemaShape::isEnum($schema)) {
            $diagnostics->warning(
                'This inline enum is not generated (only properties of generated classes get one), so the property keeps its plain type.',
                $schema->location(),
            );
        }

        $format = $schema->format();
        if ($format !== null && isset($this->formats[$format])) {
            return new ClassType($this->formats[$format]);
        }

        $members = [];
        foreach ($schema->nonNullTypes() as $type) {
            $members[] = $this->single($type, $schema, $diagnostics, $aliases);
        }

        if ($members === []) {
            return new MixedType();
        }

        // Parsed type lists hold no duplicates, so two or more members always form a valid union.
        return count($members) === 1 ? $members[0] : new UnionType(...$members);
    }

    private function explicitType(Schema $schema, Diagnostics $diagnostics): TypeModel
    {
        $at = $schema->location()->child('x-php-type');
        $value = $schema->extensions()->get('x-php-type');
        if (!is_string($value)) {
            $diagnostics->error('"x-php-type" must be a class name.', $at);

            return new MixedType();
        }

        try {
            $class = ClassName::fromFqcn($value);
        } catch (InvalidModel $exception) {
            $diagnostics->error($exception->getMessage(), $at);

            return new MixedType();
        }

        if (!$this->target->supports(Capability::from(Capability::RESERVED_NAMESPACE_SEGMENTS))) {
            foreach ($class->reservedNamespaceSegments() as $segment) {
                $diagnostics->error(
                    sprintf(
                        'Namespace "%s" contains the reserved word "%s", which PHP %s cannot parse in a namespace (allowed from PHP 8.0).',
                        $class->namespace(),
                        $segment,
                        $this->target->php()->toString(),
                    ),
                    $at,
                );

                return new MixedType();
            }
        }

        return new ClassType($class);
    }

    /**
     * @param array<string, true> $aliases
     */
    private function reference(Schema $schema, string $ref, Diagnostics $diagnostics, array $aliases): TypeModel
    {
        // An unresolved $ref was already reported while loading.
        $target = $this->graph->resolve(new ReferenceUse($ref, $schema->location()));
        if (!$target instanceof ResolvedSchema) {
            return new MixedType();
        }

        $key = $target->location()->toString();
        $declared = $this->declared($key);
        if ($declared instanceof TypeModel) {
            return $this->admitsNull($target->schema()) ? self::nullable($declared) : $declared;
        }

        if ($this->declarations->isSkipped($key)) {
            $diagnostics->warning('$ref points to a schema excluded by "x-php-skip".', $schema->location());

            return new MixedType();
        }

        // A class- or enum-shaped target without a declaration lost its namespace to an ambiguity or failed to build;
        // either was already reported.
        if (SchemaShape::isClass($target->schema()) || SchemaShape::isEnum($target->schema())) {
            return new MixedType();
        }

        if (isset($aliases[$key])) {
            $diagnostics->error('The $ref chain loops back to itself without reaching an object schema.', $target->location());

            return new MixedType();
        }

        $aliases[$key] = true;

        return $this->mapWithin($target->schema(), $diagnostics, $aliases);
    }

    /**
     * @param array<string, true> $aliases
     */
    private function single(SchemaType $type, Schema $schema, Diagnostics $diagnostics, array $aliases): TypeModel
    {
        switch ($type->value()) {
            case SchemaType::STRING:
                return $this->stringType($schema, $diagnostics);
            case SchemaType::INTEGER:
                $this->checkFormat($schema, self::INTEGER_FORMATS, 'integer', 'an int', $diagnostics);

                return ScalarType::int($this->integerRange($schema, $diagnostics));
            case SchemaType::NUMBER:
                $this->checkFormat($schema, self::NUMBER_FORMATS, 'number', 'a float', $diagnostics);

                return ScalarType::float();
            case SchemaType::BOOLEAN:
                return ScalarType::bool();
            case SchemaType::ARRAY:
                $items = $schema->items();

                return new ListType($items instanceof Schema ? $this->mapWithin($items, $diagnostics, $aliases) : new MixedType());
            default:
                $additional = $schema->additionalProperties();

                return new MapType($additional instanceof Schema ? $this->mapWithin($additional, $diagnostics, $aliases) : new MixedType());
        }
    }

    private function stringType(Schema $schema, Diagnostics $diagnostics): TypeModel
    {
        if (in_array($schema->format(), self::DATE_FORMATS, true)) {
            return new ClassType(ClassName::fromFqcn($this->target->dateTimeClass()->className()));
        }

        $this->checkFormat($schema, self::STRING_FORMATS, 'string', 'a string', $diagnostics);
        $minLength = $schema->hasKeyword('minLength') ? $schema->keyword('minLength') : null;

        return ScalarType::string(is_int($minLength) && $minLength >= 1 ? 'non-empty-string' : null);
    }

    /**
     * @param list<string> $known
     */
    private function checkFormat(Schema $schema, array $known, string $type, string $result, Diagnostics $diagnostics): void
    {
        $format = $schema->format();
        if ($format !== null && !in_array($format, $known, true)) {
            $diagnostics->warning(
                sprintf('Unknown %s format "%s"; the property stays %s.', $type, $format, $result),
                $schema->location()->child('format'),
            );
        }
    }

    private function integerRange(Schema $schema, Diagnostics $diagnostics): ?string
    {
        $min = $this->tightest($this->intKeyword($schema, 'minimum'), $this->exclusive($schema, 'exclusiveMinimum', $diagnostics), true);
        $max = $this->tightest($this->intKeyword($schema, 'maximum'), $this->exclusive($schema, 'exclusiveMaximum', $diagnostics), false);
        if ($min !== null && $max !== null && $min > $max) {
            $diagnostics->warning('The minimum is greater than the maximum, so no range is applied.', $schema->location());

            return null;
        }

        if ($max === null && $min === 0) {
            return 'non-negative-int';
        }

        if ($max === null && $min === 1) {
            return 'positive-int';
        }

        if ($min === null && $max === null) {
            return null;
        }

        return sprintf('int<%s, %s>', $min ?? 'min', $max ?? 'max');
    }

    private function intKeyword(Schema $schema, string $keyword): ?int
    {
        $value = $schema->hasKeyword($keyword) ? $schema->keyword($keyword) : null;

        return is_int($value) ? $value : null;
    }

    /**
     * The inclusive bound an exclusive one stands for.
     *
     * @param 'exclusiveMinimum'|'exclusiveMaximum' $keyword
     */
    private function exclusive(Schema $schema, string $keyword, Diagnostics $diagnostics): ?int
    {
        $value = $this->intKeyword($schema, $keyword);
        if ($value === null) {
            return null;
        }

        $lower = $keyword === 'exclusiveMinimum';
        if ($value === ($lower ? PHP_INT_MAX : PHP_INT_MIN)) {
            $diagnostics->warning(
                sprintf('"%s" leaves no integer %s it, so it is ignored.', $keyword, $lower ? 'above' : 'below'),
                $schema->location()->child($keyword),
            );

            return null;
        }

        return $lower ? $value + 1 : $value - 1;
    }

    private function tightest(?int $a, ?int $b, bool $lower): ?int
    {
        if ($a === null || $b === null) {
            return $a ?? $b;
        }

        return $lower ? max($a, $b) : min($a, $b);
    }

    private function declared(string $key): ?TypeModel
    {
        $class = $this->declarations->classAt($key);
        if ($class instanceof ClassName) {
            return new ClassType($class);
        }

        return $this->declarations->enumAt($key);
    }

    /**
     * Null is allowed by `type: [T, "null"]` and, for an enum, by a null among its values.
     */
    private function admitsNull(Schema $schema): bool
    {
        return $schema->isNullable() || in_array(null, (array) $schema->enum(), true);
    }
}
