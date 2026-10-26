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
use MSSTC4PHP\DtoGenerator\Domain\Schema\Formats;
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
    public const ONE_OF_AND_ANY_OF = '"oneOf" and "anyOf" together become one union, which admits more than the schema does.';

    private SchemaGraph $graph;

    private Declarations $declarations;

    private TargetProfile $target;

    private ConstMapper $consts;

    private TypeMeet $meet;

    private FormatMap $formats;

    /**
     * @param array<int|string, TypeModel> $formats custom formats of the config and the extensions
     * @param array<int|string, 'string'|'int'|'float'|'bool'> $known formats of the config known for a scalar
     */
    public function __construct(SchemaGraph $graph, Declarations $declarations, TargetProfile $target, array $formats, array $known = [])
    {
        $this->graph = $graph;
        $this->declarations = $declarations;
        $this->target = $target;
        $this->formats = new FormatMap($formats, $known);
        $this->consts = new ConstMapper($this->formats);
        $this->meet = new TypeMeet(ClassName::fromFqcn($target->dateTimeClass()->className()), $declarations);
    }

    /**
     * The meet of the types of a property that several members of an allOf describe.
     */
    public function meet(): TypeMeet
    {
        return $this->meet;
    }

    /**
     * The schema whose x-php-collection decides how a property holds its list: the property's own, or the first along
     * its $ref chain.
     */
    public function collectionCarrier(Schema $schema): ?Schema
    {
        $visited = [];
        while (!$schema->extensions()->has('x-php-collection')) {
            $visited[] = $schema->location()->toString();
            $ref = $schema->ref();
            $target = $ref === null ? null : $this->graph->resolve(new ReferenceUse($ref, $schema->location()));
            if (!$target instanceof ResolvedSchema || in_array($target->location()->toString(), $visited, true)) {
                return null;
            }

            $schema = $target->schema();
        }

        return $schema;
    }

    public function map(Schema $schema, Diagnostics $diagnostics): TypeModel
    {
        return $this->mapWithin($schema, $diagnostics, []);
    }

    /**
     * @param array<string, bool> $aliases alias schemas being inlined, to stop $ref loops; false once a list or map holds
     *                                     the place, where a loop is a recursive structure
     */
    private function mapWithin(Schema $schema, Diagnostics $diagnostics, array $aliases): TypeModel
    {
        $type = $this->bareType($schema, $diagnostics, $aliases);

        return $this->admitsNull($schema) ? NullableType::of($type) : $type;
    }

    /**
     * @param array<string, bool> $aliases
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

        if (!SchemaShape::isClass($schema) && SchemaShape::mayBeObject($schema) && $schema->allOf() !== [] && SchemaShape::hasUnion($schema)) {
            $diagnostics->error('"allOf" together with "oneOf" or "anyOf" is not supported.', $schema->location());

            return new MixedType();
        }

        // A discriminated union becomes a base class only as a named schema; anywhere else it is a plain union.
        // Members without a type of their own only constrain the schema's type (`anyOf` of patterns).
        $union = array_merge($schema->oneOf(), $schema->anyOf());
        if ($this->typed($union, true) !== [] && ($schema->propertyNames() === [] || SchemaShape::isDiscriminated($schema))) {
            if ($schema->allOf() !== []) {
                $diagnostics->warning('"allOf" beside a typed "oneOf" or "anyOf" is not represented; the union alone gives the type.', $schema->location());
            }

            return $this->union($schema, $diagnostics, $aliases);
        }

        if (SchemaShape::isClass($schema)) {
            $diagnostics->error(
                'This inline object is not generated (only properties of generated classes get one); move it to components/schemas (definitions in Swagger 2.0) and use $ref.',
                $schema->location(),
            );

            return new MixedType();
        }

        $typed = $this->typed($schema->allOf(), false);
        // A member that only says "object" adds nothing to one that is an object (`allOf: [$ref, {type: object}]`).
        $specific = array_values(array_filter($typed, static fn (Schema $member): bool => !SchemaShape::isPlainObject($member)));
        $plain = $specific === [] ? [] : array_values(array_filter($typed, static fn (Schema $member): bool => SchemaShape::isPlainObject($member)));
        $specific = $specific === [] ? $typed : $specific;
        if (count($specific) > 1) {
            $diagnostics->error('"allOf" combines several typed schemas that are not objects, which no PHP type expresses; keep one of them.', $schema->location());

            return new MixedType();
        }

        if ($specific !== []) {
            if ($plain !== [] && !$this->canBeObject($specific[0])) {
                $diagnostics->error('An "allOf" member requires an object beside one that can be no object, so no value passes; drop one of them.', $schema->location());

                return new MixedType();
            }

            $type = $this->mapWithin($specific[0], $diagnostics, $aliases);
            if ($plain !== []) {
                $type = $this->withNullOf($type, $plain);
            }

            return $this->consts->narrow($schema, $type, $diagnostics);
        }

        if (SchemaShape::isMixedEnum($schema)) {
            return $this->mixedEnum($schema, $diagnostics);
        }

        $booleans = SchemaShape::booleanEnumValues($schema);
        // A `const` beside it narrows the type further, below.
        if ($booleans !== null && !$schema->hasKeyword('const')) {
            return ScalarType::bool(count($booleans) === 1 ? LiteralType::of($booleans[0]) : null);
        }

        if (SchemaShape::isEnum($schema)) {
            $diagnostics->warning(
                'This inline enum is not generated (only properties of generated classes get one), so the property keeps its plain type.',
                $schema->location(),
            );
        }

        if (SchemaShape::contradictsItsEnum($schema)) {
            $diagnostics->warning('"type" admits none of the enum values, so the enum is ignored and the property keeps its type.', $schema->location()->child('type'));
        } elseif ($schema->enum() !== null && !SchemaShape::isEnum($schema) && $booleans === null) {
            $diagnostics->warning(
                'This enum has no string or integer value, so it is not generated and the property keeps its plain type.',
                $schema->location(),
            );
        }

        if ($schema->hasKeyword('const')) {
            $const = $this->consts->type($schema, $diagnostics);
            if ($const instanceof TypeModel) {
                return $const;
            }
        }

        $mapped = $this->formats->typeOf($schema);
        if ($mapped instanceof TypeModel) {
            return $mapped;
        }

        $members = [];
        foreach ($schema->nonNullTypes() as $type) {
            $members[] = $this->single($type, $schema, $diagnostics, $aliases);
        }

        if ($members === []) {
            return $this->consts->narrow($schema, new MixedType(), $diagnostics);
        }

        // `type: [integer, number]` with a format of whole numbers gives int twice.
        return $this->consts->narrow($schema, UnionType::of(...$members), $diagnostics);
    }

    /**
     * Null members make the union nullable; one member of unknown type makes all of it mixed.
     *
     * @param array<string, bool> $aliases
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
            if (SchemaShape::isNull($member)) {
                $nullable = true;

                continue;
            }

            // A member that only constrains (a pattern) admits the schema's own type.
            if ($this->typed([$member], true) === [] && $schema->nonNullTypes() !== []) {
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

        $type = $this->subsume(UnionType::of(...$members));

        return $nullable ? NullableType::of($type) : $type;
    }

    /**
     * The schemas whose `const` or mixed enum limits the values: the schema itself, `allOf` members and what a `$ref`
     * leads to, at any depth, short of a generated class, whose default is no constant expression anyway.
     *
     * @return list<Schema>
     */
    public function valueSources(Schema $schema): array
    {
        $visited = [];

        return $this->valueSourcesWithin($schema, $visited);
    }

    /**
     * @param list<string> $visited locations reached through `$ref`, each followed once
     *
     * @return list<Schema>
     */
    private function valueSourcesWithin(Schema $schema, array &$visited): array
    {
        $found = $schema->hasKeyword('const') || SchemaShape::isMixedEnum($schema) ? [$schema] : [];
        foreach ($schema->allOf() as $member) {
            $found = array_merge($found, $this->valueSourcesWithin($member, $visited));
        }

        $ref = $schema->ref();
        $target = $ref === null ? null : $this->graph->resolve(new ReferenceUse($ref, $schema->location()));
        if (!$target instanceof ResolvedSchema || in_array($target->location()->toString(), $visited, true) || SchemaShape::isClass($target->schema())) {
            return $found;
        }

        $visited[] = $target->location()->toString();

        return array_merge($found, $this->valueSourcesWithin($target->schema(), $visited));
    }

    /**
     * A plain `string` already admits `'a'` and `non-empty-string`: the refined member of a kind is dropped beside the
     * plain one.
     */
    private function subsume(TypeModel $type): TypeModel
    {
        if (!$type instanceof UnionType) {
            return $type;
        }

        $members = $type->members();
        $booleans = [];
        foreach ($members as $member) {
            if ($member instanceof ScalarType && $member->kind() === 'bool') {
                $booleans[] = $member->phpDoc();
            }
        }

        // `true|false` is all of bool.
        if (in_array('true', $booleans, true) && in_array('false', $booleans, true)) {
            $members[] = ScalarType::bool();
        }

        $plain = [];
        foreach ($members as $member) {
            if ($member instanceof ScalarType && $member->phpDoc() === null) {
                $plain[] = $member->kind();
            }
        }

        $kept = [];
        foreach ($members as $member) {
            if (!$member instanceof ScalarType || $member->phpDoc() === null || !in_array($member->kind(), $plain, true)) {
                $kept[] = $member;
            }
        }

        return UnionType::of(...$kept);
    }

    /**
     * The members of a composition that carry a type; the others only constrain it.
     *
     * @param list<Schema> $members
     * @param bool $byConst whether a lone `const` gives a type: each member of a union is one value, while beside a type
     *                      in `allOf` it only narrows that type
     *
     * @return list<Schema>
     */
    private function typed(array $members, bool $byConst): array
    {
        return array_values(array_filter(
            $members,
            static fn (Schema $member): bool => $member->ref() !== null
                || $member->nonNullTypes() !== []
                || $member->enum() !== null
                || ($byConst && $member->hasKeyword('const'))
                || SchemaShape::isComposed($member)
                || $member->propertyNames() !== []
                || $member->extensions()->has('x-php-type'),
        ));
    }

    /**
     * Whether a JSON object may pass the schema, as its `type`, values, `$ref`, `allOf` and `oneOf`/`anyOf` tell; the PHP
     * type cannot, as a date or an `x-php-type` makes a string a class. A reference that loops or that the loader could
     * not follow is taken for one.
     *
     * @param list<string> $seen the schemas on the path to this one, so two references to one schema are no loop
     */
    private function canBeObject(Schema $schema, array $seen = []): bool
    {
        $key = $schema->location()->toString();
        if (in_array($key, $seen, true)) {
            return true;
        }

        $seen[] = $key;
        $ref = $schema->ref();
        if ($ref !== null) {
            $target = $this->graph->resolve(new ReferenceUse($ref, $schema->location()));

            return !$target instanceof ResolvedSchema || $this->canBeObject($target->schema(), $seen);
        }

        $types = $schema->nonNullTypes();
        $values = $schema->enum();
        if (($types !== [] && !in_array(SchemaType::from(SchemaType::OBJECT), $types, true)) || ($values !== null && array_filter($values, 'is_array') === [])) {
            return false;
        }

        foreach ($schema->allOf() as $member) {
            if (!$this->canBeObject($member, $seen)) {
                return false;
            }
        }

        $union = array_merge($schema->oneOf(), $schema->anyOf());
        foreach ($union as $member) {
            if ($this->canBeObject($member, $seen)) {
                return true;
            }
        }

        return $union === [];
    }

    /**
     * Every member of an allOf applies: the type keeps `null` only when each `{type: object}` member admits it too. A
     * union keeps its other members, which a date format or `x-php-type` may make classes as well.
     *
     * @param non-empty-list<Schema> $plain
     */
    private function withNullOf(TypeModel $type, array $plain): TypeModel
    {
        if (!$type instanceof NullableType) {
            return $type;
        }

        foreach ($plain as $member) {
            if (!$member->isNullable()) {
                return $type->inner();
            }
        }

        return $type;
    }

    /**
     * @param array<string, bool> $aliases
     *
     * @return array<string, bool>
     */
    private function contained(array $aliases): array
    {
        return array_fill_keys(array_keys($aliases), false);
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
     * @param array<string, bool> $aliases
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
            return $this->admitsNull($target->schema()) ? NullableType::of($declared) : $declared;
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
            // A value that holds itself in a list or map (a JSON value) has no PHP type for the inner levels.
            if ($aliases[$key]) {
                $diagnostics->error('The $ref chain loops back to itself without reaching an object schema.', $target->location());
            }

            return new MixedType();
        }

        $aliases[$key] = true;

        return $this->mapWithin($target->schema(), $diagnostics, $aliases);
    }

    /**
     * @param array<string, bool> $aliases
     */
    private function single(SchemaType $type, Schema $schema, Diagnostics $diagnostics, array $aliases): TypeModel
    {
        switch ($type->value()) {
            case SchemaType::STRING:
                return $this->stringType($schema, $diagnostics);
            case SchemaType::INTEGER:
                FormatCheck::check($schema, 'int', $this->formats, $diagnostics);

                return ScalarType::int($this->integerRange($schema, $diagnostics));
            case SchemaType::NUMBER:
                // `type: number, format: integer` (Xero) holds whole numbers.
                if (in_array($schema->normalizedFormat(), Formats::INTEGERS, true)) {
                    return ScalarType::int($this->integerRange($schema, $diagnostics));
                }

                FormatCheck::check($schema, 'float', $this->formats, $diagnostics);

                return ScalarType::float();
            case SchemaType::BOOLEAN:
                return ScalarType::bool();
            case SchemaType::ARRAY:
                $items = $schema->items();

                return new ListType($items instanceof Schema ? $this->mapWithin($items, $diagnostics, $this->contained($aliases)) : new MixedType());
            default:
                $additional = $schema->additionalProperties();

                return new MapType($additional instanceof Schema ? $this->mapWithin($additional, $diagnostics, $this->contained($aliases)) : new MixedType());
        }
    }

    private function stringType(Schema $schema, Diagnostics $diagnostics): TypeModel
    {
        if (in_array($schema->normalizedFormat(), Formats::DATES, true)) {
            return new ClassType(ClassName::fromFqcn($this->target->dateTimeClass()->className()));
        }

        FormatCheck::check($schema, 'string', $this->formats, $diagnostics);
        if (in_array($schema->normalizedFormat(), Formats::NUMERIC_STRINGS, true)) {
            return ScalarType::string('numeric-string');
        }

        $minLength = $schema->hasKeyword('minLength') ? $schema->keyword('minLength') : null;

        return ScalarType::string(is_int($minLength) && $minLength >= 1 ? 'non-empty-string' : null);
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

        // A whole float (`maximum: 1000.0`) bounds integers as well.
        return is_float($value) ? WholeNumber::of($value) : (is_int($value) ? $value : null);
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
