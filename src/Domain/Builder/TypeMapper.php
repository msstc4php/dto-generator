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
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;
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

    public const ONE_OF_AND_ANY_OF = '"oneOf" and "anyOf" together become one union, which admits more than the schema does.';

    private const DATE_FORMATS = ['date-time', 'date'];

    private const INTEGER_FORMATS = ['int32', 'int64'];

    private const NUMBER_FORMATS = ['float', 'double'];

    private SchemaGraph $graph;

    private Declarations $declarations;

    private TargetProfile $target;

    /** @var array<int|string, TypeModel> */
    private array $formats;

    /**
     * @param array<int|string, TypeModel> $formats custom formats of the config and the extensions
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

        $ref = $schema->ref();
        if ($ref !== null) {
            return $this->reference($schema, $ref, $diagnostics, $aliases);
        }

        if (SchemaShape::mayBeObject($schema) && $schema->allOf() !== [] && SchemaShape::hasUnion($schema)) {
            $diagnostics->error('"allOf" together with "oneOf" or "anyOf" is not supported.', $schema->location());

            return new MixedType();
        }

        // A discriminated union becomes a base class only as a named schema; anywhere else it is a plain union.
        // Members without a type of their own only constrain the schema's type (`anyOf` of patterns).
        $union = array_merge($schema->oneOf(), $schema->anyOf());
        if ($this->typed($union) !== [] && ($schema->propertyNames() === [] || SchemaShape::isDiscriminated($schema))) {
            if ($schema->allOf() !== []) {
                $diagnostics->warning('"allOf" beside a typed "oneOf" or "anyOf" is not represented; the union alone gives the type.', $schema->location());
            }

            return $this->union($schema, $diagnostics, $aliases);
        }

        if (SchemaShape::isClass($schema)) {
            $diagnostics->error(
                'This inline object is not generated (only properties of generated classes get one); move it to components/schemas and use $ref.',
                $schema->location(),
            );

            return new MixedType();
        }

        $typed = $this->typed($schema->allOf());
        if (count($typed) > 1) {
            $diagnostics->error('"allOf" combines several typed schemas that are not objects, which no PHP type expresses; keep one of them.', $schema->location());

            return new MixedType();
        }

        if ($typed !== []) {
            return $this->mapWithin($typed[0], $diagnostics, $aliases);
        }

        if (SchemaShape::isMixedEnum($schema)) {
            return $this->mixedEnum($schema, $diagnostics);
        }

        if (SchemaShape::isEnum($schema)) {
            $diagnostics->warning(
                'This inline enum is not generated (only properties of generated classes get one), so the property keeps its plain type.',
                $schema->location(),
            );
        }

        if ($schema->enum() !== null && !SchemaShape::isEnum($schema)) {
            $diagnostics->warning(
                'This enum has no string or integer value, so it is not generated and the property keeps its plain type.',
                $schema->location(),
            );
        }

        if ($schema->hasKeyword('const')) {
            $const = $this->constType($schema, $diagnostics);
            if ($const instanceof TypeModel) {
                return $const;
            }
        }

        $format = $schema->format();
        if ($format !== null && isset($this->formats[$format])) {
            return $this->formats[$format];
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

    /**
     * Null members make the union nullable; one member of unknown type makes all of it mixed.
     *
     * @param array<string, true> $aliases
     */
    private function union(Schema $schema, Diagnostics $diagnostics, array $aliases): TypeModel
    {
        if ($schema->oneOf() !== [] && $schema->anyOf() !== []) {
            $diagnostics->warning(self::ONE_OF_AND_ANY_OF, $schema->location());
        }

        $members = [];
        $nullable = false;
        $mixed = false;
        foreach (array_merge($schema->oneOf(), $schema->anyOf()) as $member) {
            if ($member->ref() === null && $member->isNullable() && $member->nonNullTypes() === [] && !SchemaShape::isComposed($member)) {
                $nullable = true;

                continue;
            }

            // A member that only constrains (a pattern) admits the schema's own type.
            if ($this->typed([$member]) === [] && $schema->nonNullTypes() !== []) {
                foreach ($schema->nonNullTypes() as $own) {
                    $members[] = $this->single($own, $schema, $diagnostics, $aliases);
                }

                continue;
            }

            $type = $this->mapWithin($member, $diagnostics, $aliases);
            if ($type instanceof NullableType) {
                $nullable = true;
                $type = $type->inner();
            }

            $mixed = $mixed || $type instanceof MixedType;
            $members[] = $type;
        }

        if ($mixed || $members === []) {
            return new MixedType();
        }

        $type = UnionType::of(...$members);

        return $nullable ? self::nullable($type) : $type;
    }

    /**
     * The members of a composition that carry a type; the others only constrain it.
     *
     * @param list<Schema> $members
     *
     * @return list<Schema>
     */
    private function typed(array $members): array
    {
        return array_values(array_filter(
            $members,
            static fn (Schema $member): bool => $member->ref() !== null
                || $member->nonNullTypes() !== []
                || $member->enum() !== null
                || SchemaShape::isComposed($member)
                || $member->propertyNames() !== []
                || $member->extensions()->has('x-php-type'),
        ));
    }

    private function mixedEnum(Schema $schema, Diagnostics $diagnostics): TypeModel
    {
        $declared = array_map(static fn (SchemaType $type): string => $type->value(), $schema->nonNullTypes());
        $numeric = in_array(SchemaType::INTEGER, $declared, true) || in_array(SchemaType::NUMBER, $declared, true);
        if ($declared !== [] && (!in_array(SchemaType::STRING, $declared, true) || !$numeric)) {
            $diagnostics->error('"type" does not match the enum values, which are strings and integers.', $schema->location()->child('type'));

            return new MixedType();
        }

        $diagnostics->warning(
            'The enum mixes strings and integers, which no PHP enum can back; the property takes either.',
            $schema->location()->child('enum'),
        );
        $values = $schema->enum() ?? [];
        $strings = array_unique(array_filter($values, 'is_string'));
        $ints = array_unique(array_filter($values, 'is_int'));

        return new UnionType(ScalarType::string(LiteralType::union($strings)), ScalarType::int(LiteralType::union($ints)));
    }

    /**
     * The type of the one allowed value; null leaves the schema's own type (a null, array or object constant).
     */
    private function constType(Schema $schema, Diagnostics $diagnostics): ?TypeModel
    {
        $value = Json::value($schema->keyword('const'));
        if (is_string($value)) {
            $type = ScalarType::string(LiteralType::of($value));
            $kind = SchemaType::STRING;
        } elseif (is_int($value)) {
            $type = ScalarType::int(LiteralType::of($value));
            $kind = SchemaType::INTEGER;
        } elseif (is_bool($value)) {
            $type = ScalarType::bool(LiteralType::of($value));
            $kind = SchemaType::BOOLEAN;
        } elseif (is_float($value)) {
            $type = ScalarType::float();
            $kind = SchemaType::NUMBER;
        } else {
            return null;
        }

        $declared = array_map(static fn (SchemaType $type): string => $type->value(), $schema->nonNullTypes());
        // JSON Schema counts integers as numbers too.
        $fits = in_array($kind, $declared, true) || ($kind === SchemaType::INTEGER && in_array(SchemaType::NUMBER, $declared, true));
        if ($declared !== [] && !$fits) {
            $diagnostics->error('"const" is not of the declared type.', $schema->location()->child('const'));

            return new MixedType();
        }

        return $type;
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
