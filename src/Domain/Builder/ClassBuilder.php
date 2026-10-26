<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Exception\IncompatibleTarget;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassKind;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassType;
use MSSTC4PHP\DtoGenerator\Domain\Model\DocModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\Identifier;
use MSSTC4PHP\DtoGenerator\Domain\Model\ListType;
use MSSTC4PHP\DtoGenerator\Domain\Model\MapType;
use MSSTC4PHP\DtoGenerator\Domain\Model\NullableType;
use MSSTC4PHP\DtoGenerator\Domain\Model\PropertyModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ScalarType;
use MSSTC4PHP\DtoGenerator\Domain\Model\TypeModel;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaType;
use MSSTC4PHP\DtoGenerator\Domain\Shared\DefaultValue;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;
use MSSTC4PHP\DtoGenerator\Domain\Target\Capability;
use MSSTC4PHP\DtoGenerator\Domain\Target\Mutability;
use MSSTC4PHP\DtoGenerator\Domain\Target\TargetProfile;

/**
 * Builds the class of one object schema (spec §5.2, §5.4, §5.5) and checks the x- vocabulary of its properties (§7).
 *
 * @phpstan-import-type JsonValue from Json
 */
final class ClassBuilder
{
    private NameResolver $names;

    private Superseded $superseded;

    private TypeMapper $types;

    private TargetProfile $target;

    /** @var list<string> */
    private array $aliases;

    private ?PropertyView $view;

    /**
     * @param list<string> $aliases keys of attributeAliases, which properties may carry
     * @param PropertyView|null $view the view of a direction-dependent class; null keeps every property
     */
    public function __construct(NameResolver $names, TypeMapper $types, TargetProfile $target, array $aliases = [], ?PropertyView $view = null, ?Superseded $superseded = null)
    {
        $this->superseded = $superseded ?? new Superseded();
        $this->names = $names;
        $this->types = $types;
        $this->target = $target;
        $this->aliases = $aliases;
        $this->view = $view;
    }

    public static function isSkipped(Schema $schema, Diagnostics $diagnostics): bool
    {
        if (!$schema->extensions()->has('x-php-skip')) {
            return false;
        }

        $value = $schema->extensions()->get('x-php-skip');
        if (!is_bool($value)) {
            $diagnostics->error('"x-php-skip" must be true or false.', $schema->location()->child('x-php-skip'));

            return false;
        }

        return $value;
    }

    /**
     * The class takes the properties of every part of its composition; its parent's arrive through inheritance.
     */
    public function build(ClassName $name, Schema $schema, Diagnostics $diagnostics, ?Composition $composition = null): ClassModel
    {
        $composition ??= Composition::of($schema);
        $properties = [];
        $taken = [];
        $byWireName = [];
        $sources = $composition->propertySources();
        ReadWriteMode::check($schema, $diagnostics);
        $view = $this->view instanceof PropertyView && $this->view->appliesTo($schema) ? $this->view : null;
        // The other view's properties are left out quietly, unlike x-php-skip.
        $unwanted = self::skippedWireNames($sources);
        $skipped = $unwanted + ($view instanceof PropertyView ? $view->excluded(self::kept($sources)) : []);
        $spelled = $this->spelledNames($sources, $unwanted);
        $spelled = array_replace($spelled, $this->numberedNames($sources, $unwanted, $spelled, $diagnostics));
        foreach ($sources as [$wireName, $propertySchema]) {
            ExtensionVocabulary::checkProperty($propertySchema, $diagnostics, $this->aliases);
            if (self::isSkipped($propertySchema, $diagnostics) && $composition->isRequired($wireName)) {
                $diagnostics->warning(
                    sprintf('Property "%s" is required but excluded by "x-php-skip".', $wireName),
                    $propertySchema->location()->child('x-php-skip'),
                );
            }

            if (isset($skipped[$wireName])) {
                continue;
            }

            $property = $this->property($wireName, $propertySchema, $composition->isRequired($wireName), $spelled, $diagnostics);
            if (!$property instanceof PropertyModel) {
                continue;
            }

            $earlier = $byWireName[$wireName] ?? null;
            if ($earlier instanceof PropertyModel) {
                // Every member of the allOf applies: the property has the type of the values both admit.
                $meet = $this->types->meet()->of($earlier->type(), $property->type());
                if (!$meet instanceof TypeModel) {
                    $diagnostics->error(
                        sprintf(
                            'Property "%s" of %s is %s here, but %s in an earlier allOf member.',
                            $wireName,
                            $name->fqcn(),
                            $property->type()->describe(),
                            $earlier->type()->describe(),
                        ),
                        $propertySchema->location(),
                    );
                } elseif ($earlier->name() !== $property->name() || $this->defaultOf($earlier) !== $this->defaultOf($property)) {
                    $diagnostics->error(
                        sprintf('Property "%s" of %s has another PHP name or default here than in an earlier allOf member.', $wireName, $name->fqcn()),
                        $propertySchema->location(),
                    );
                } else {
                    $this->superseded->add($earlier->type());
                    $this->superseded->add($property->type());
                    if ($meet->describe() !== $earlier->type()->describe()) {
                        $narrower = $meet->describe() === $property->type()->describe() ? $property : $property->withType($meet);
                        $byWireName[$wireName] = $narrower;
                        $properties[array_search($earlier, $properties, true)] = $narrower;
                    }
                }

                continue;
            }

            // Accessors are case-insensitive in PHP, so $url and $URL would both declare getUrl().
            $key = Identifier::asciiLower($property->name());
            if (isset($taken[$key])) {
                $diagnostics->error(
                    sprintf('Property "%s" becomes $%s, which "%s" already uses; set "x-php-name" on one of them, in the document or "propertyNames" of the source.', $wireName, $property->name(), $taken[$key]),
                    $propertySchema->location(),
                );

                continue;
            }

            $taken[$key] = $wireName;
            $byWireName[$wireName] = $property;
            $properties[] = $property;
        }

        $additional = $this->additionalProperties($schema, $view, isset($taken['additionalproperties']), $diagnostics);
        if ($additional instanceof PropertyModel) {
            $properties[] = $additional;
        }

        $model = new ClassModel(
            $name,
            ClassKind::from(ClassKind::FINAL),
            $composition->parent(),
            $properties,
            $this->mutability($schema, $diagnostics),
            new DocModel($schema->description(), $schema->isDeprecated()),
            $schema->location(),
        );

        return $model->withRequiredInherited(...$this->requiredInherited($composition));
    }

    /**
     * A required property whose schema admits null is optional, here as for a class's own; every declaration of the
     * ancestor applies.
     *
     * @return list<string>
     */
    private function requiredInherited(Composition $composition): array
    {
        $wireNames = [];
        foreach ($composition->inheritedRequirements() as $wireName => $declarations) {
            // The ancestor's own build reports what its declarations hold.
            $type = null;
            foreach ($declarations as $declaration) {
                $mapped = $this->types->map($declaration, new Diagnostics());
                $type = $type instanceof TypeModel ? $this->types->meet()->of($type, $mapped) : $mapped;
                if (!$type instanceof TypeModel) {
                    break;
                }
            }

            if ($type instanceof TypeModel && !$type instanceof NullableType) {
                $wireNames[] = (string) $wireName;
            }
        }

        return $wireNames;
    }

    /**
     * `properties` together with an `additionalProperties` schema: the extra keys land in $additionalProperties (spec §5.1).
     */
    private function additionalProperties(Schema $schema, ?PropertyView $view, bool $nameTaken, Diagnostics $diagnostics): ?PropertyModel
    {
        $additional = $schema->additionalProperties();
        if (!$additional instanceof Schema || ($view instanceof PropertyView && !$view->admits($additional))) {
            return null;
        }

        if ($nameTaken) {
            $diagnostics->error(
                'The class already has a property $additionalProperties; rename it with "x-php-name", in the document or "propertyNames" of the source.',
                $schema->location()->child('additionalProperties'),
            );

            return null;
        }

        return new PropertyModel(
            'additionalProperties',
            'additionalProperties',
            new MapType($this->types->map($additional, $diagnostics)),
            false,
            new DefaultValue([]),
            new DocModel('Properties the schema does not declare.'),
            $additional->location(),
            [],
            true,
        );
    }

    /**
     * The declarations of the properties no member of the composition excludes with x-php-skip.
     *
     * @param list<array{string, Schema}> $sources
     *
     * @return list<array{string, Schema}>
     */
    public static function kept(array $sources): array
    {
        $skipped = self::skippedWireNames($sources);
        $kept = [];
        foreach ($sources as $source) {
            if (!isset($skipped[$source[0]])) {
                $kept[] = $source;
            }
        }

        return $kept;
    }

    /**
     * A property any merged member excludes stays excluded, whichever member declares it first.
     *
     * @param list<array{string, Schema}> $sources
     *
     * @return array<array-key, string> wire name → wire name; a numeric one is an integer key
     */
    private static function skippedWireNames(array $sources): array
    {
        $skipped = [];
        foreach ($sources as [$wireName, $propertySchema]) {
            // Reported when the property itself is built.
            if (self::isSkipped($propertySchema, new Diagnostics())) {
                $skipped[$wireName] = $wireName;
            }
        }

        return $skipped;
    }

    /**
     * The default a schema sets, as JSON writes it (`1` and `1.0` of a number are the same); a null one only stands for
     * a missing value.
     */
    private function defaultOf(PropertyModel $property): string
    {
        $default = $property->default();

        return $default instanceof DefaultValue && $default->value() !== null ? $default->toJson() : '';
    }

    /**
     * @param array<array-key, string> $spelled
     */
    private function property(string $wireName, Schema $schema, bool $required, array $spelled, Diagnostics $diagnostics): ?PropertyModel
    {
        $name = $this->propertyName($wireName, $schema, $required, $spelled[$wireName] ?? null, $diagnostics);
        if ($name === null) {
            return null;
        }

        $type = $this->types->map($schema, $diagnostics);
        $required = $required && !$type instanceof NullableType;
        $default = null;
        if (!$required) {
            $type = NullableType::of($type);
            $default = $this->defaultFor($schema, $type, $diagnostics) ?? new DefaultValue(null);
        }

        return new PropertyModel(
            $name,
            $wireName,
            $type,
            $required,
            $default,
            new DocModel($schema->description(), $schema->isDeprecated()),
            $schema->location(),
        );
    }

    /**
     * A property whose PHP name another one of the class has first (letter case ignored, as accessors are) is numbered
     * from 2 past every name a property has by itself, an explicit one included, alike for every view and every allOf
     * member that declares it; an `x-php-name` that clashes stays an error, reported where the property is built.
     *
     * @param list<array{string, Schema}> $sources
     * @param array<array-key, string> $skipped by wire name
     * @param array<array-key, string> $spelled by wire name
     *
     * @return array<array-key, string> by wire name, a numeric one as an int
     */
    private function numberedNames(array $sources, array $skipped, array $spelled, Diagnostics $diagnostics): array
    {
        $names = [];
        foreach ($sources as [$wireName, $schema]) {
            $explicit = $this->explicitName($schema);
            $name = $explicit ?? $spelled[$wireName] ?? $this->names->propertyName($wireName);
            if (!isset($skipped[$wireName]) && !isset($names[$wireName]) && $name !== null) {
                $names[$wireName] = [$name, $explicit !== null, $schema];
            }
        }

        $natural = array_flip(array_map(static fn (array $entry): string => Identifier::asciiLower($entry[0]), $names));
        $holders = [];
        $numbered = [];
        foreach ($names as $wireName => [$name, $explicit, $schema]) {
            $key = Identifier::asciiLower($name);
            if (!isset($holders[$key]) || $explicit) {
                $holders[$key] ??= $wireName;

                continue;
            }

            $number = 2;
            while (isset($holders[$key . $number]) || isset($natural[$key . $number])) {
                $number++;
            }

            $numbered[$wireName] = $name . $number;
            $holders[$key . $number] = $wireName;
            $diagnostics->warning(
                sprintf('Property "%s" becomes $%s, which "%s" already uses, so it is $%s; set "x-php-name" to name it, in the document or "propertyNames" of the source.', $wireName, $name, $holders[$key], $name . $number),
                $schema->location(),
            );
        }

        return $numbered;
    }

    /**
     * Names that only their signs tell apart ("+1" and "-1", "utc+1" and "utc-1"), or that have no other characters
     * ("*"), get their signs as words ({@see NameResolver::spelledGroups()}), in every view alike; a name one no other
     * matches stays as it is. Spelled names that would meet another name leave every name as it is, and the collision
     * is reported.
     *
     * @param list<array{string, Schema}> $sources
     * @param array<array-key, string> $skipped by wire name
     *
     * @return array<array-key, string> by wire name, a numeric one as an int
     */
    private function spelledNames(array $sources, array $skipped): array
    {
        $groups = [];
        foreach ($sources as [$wireName, $schema]) {
            if (!isset($skipped[$wireName]) && self::explicitName($schema) === null) {
                $plain = $this->names->propertyName($wireName);
                $groups[$plain === null ? '' : Identifier::asciiLower($plain)][$wireName] = $wireName;
            }
        }

        return $this->names->spelledGroups($groups, fn (string $wireName): ?string => $this->names->propertyName($wireName));
    }

    /**
     * The name `x-php-name` gives, when it is one PHP accepts.
     */
    private function explicitName(Schema $schema): ?string
    {
        $override = $schema->extensions()->has('x-php-name') ? $schema->extensions()->get('x-php-name') : null;

        return is_string($override) && Identifier::isValid($override) && $override !== 'this' && !Identifier::isSuperglobal($override) ? $override : null;
    }

    /**
     * A declaration with its own x-php-name takes it, whatever name another declaration of the property got; else the
     * spelled or numbered name the class gave it.
     */
    private function propertyName(string $wireName, Schema $schema, bool $required, ?string $given, Diagnostics $diagnostics): ?string
    {
        $override = $this->explicitName($schema);
        if ($override !== null) {
            return $override;
        }

        if ($schema->extensions()->has('x-php-name')) {
            $diagnostics->error('"x-php-name" must be a PHP identifier other than "this" and the superglobals.', $schema->location()->child('x-php-name'));
        }

        $name = $given ?? $this->names->propertyName($wireName);
        if ($name === null) {
            $diagnostics->warning(
                sprintf('Property name "%s" has no usable characters, so the class leaves it out%s; set "x-php-name" to keep it, in the document or "propertyNames" of the source.', $wireName, $required ? ', though it is required' : ''),
                $schema->location(),
            );
        }

        return $name;
    }

    /**
     * The values `const` and a mixed enum allow, here or behind a `$ref`, are compared as JSON values, whether or not the
     * type carries them as a PHPDoc literal. A JSON-equal value can still not fit the PHP type (2.0 for an int); the
     * type check after this one reports that.
     *
     * @param JsonValue $value
     */
    private function forbiddenBySchema(Schema $schema, $value): ?string
    {
        foreach ($this->types->valueSources($schema) as $source) {
            $forbidden = $this->forbiddenBy($source, $value);
            if ($forbidden !== null) {
                return $forbidden;
            }
        }

        return null;
    }

    /**
     * @param JsonValue $value
     */
    private function forbiddenBy(Schema $source, $value): ?string
    {
        if ($source->hasKeyword('const')) {
            $const = Json::value($source->keyword('const'));

            return $this->sameJson($value, $const) ? null : sprintf('Default %s is not the "const" value %s; null is used instead.', $this->json($value), $this->json($const));
        }

        foreach ($source->enum() ?? [] as $allowed) {
            if ($this->sameJson($value, $allowed)) {
                return null;
            }
        }

        return sprintf('Default %s is not one of the "enum" values; null is used instead.', $this->json($value));
    }

    /**
     * JSON equality: 2 and 2.0 are the same number, "2" and 2 are not, and the keys of an object have no order.
     *
     * @param JsonValue $a
     * @param JsonValue $b
     */
    private function sameJson($a, $b): bool
    {
        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
            return (float) $a === (float) $b;
        }

        if (!is_array($a) || !is_array($b)) {
            return $a === $b;
        }

        if (Json::isList($a) !== Json::isList($b) || count($a) !== count($b)) {
            return false;
        }

        foreach ($a as $key => $item) {
            if (!array_key_exists($key, $b) || !$this->sameJson(Json::value($item), Json::value($b[$key]))) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param JsonValue $value
     */
    private function json($value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private function defaultFor(Schema $schema, TypeModel $type, Diagnostics $diagnostics): ?DefaultValue
    {
        $default = $schema->default();
        if (!$default instanceof DefaultValue || $default->value() === null) {
            return $default;
        }

        $at = $schema->location()->child('default');
        $forbidden = $this->forbiddenBySchema($schema, $default->value());
        if ($forbidden !== null) {
            $diagnostics->error($forbidden, $at);

            return null;
        }

        $inner = $type instanceof NullableType ? $type->inner() : $type;
        $value = $default->value();
        // `new X()` waits for every class to be built: ObjectDefaults keeps it only where X takes no arguments.
        if ($inner instanceof ClassType && $value === [] && $this->target->supports(Capability::from(Capability::NEW_IN_INITIALIZERS))) {
            return $default;
        }

        // Only a non-empty list reaches its item type; any other value of a list is checked as a plain mismatch.
        $reachesItems = is_array($value) && $value !== [] && Json::isList($value);
        $unrepresentable = $inner instanceof ListType && !$reachesItems ? null : self::unrepresentable($inner);
        if ($unrepresentable instanceof ClassType) {
            $diagnostics->warning(sprintf('A default for %s cannot be a PHP constant expression; null is used instead.', $unrepresentable->describe()), $at);

            return null;
        }

        if ($unrepresentable instanceof MapType) {
            $diagnostics->warning('A default for a map is not generated, because JSON object keys do not reliably survive as PHP array keys; null is used instead.', $at);

            return null;
        }

        if (self::containsNonFiniteFloat($default->value())) {
            $diagnostics->error('A default must be a finite number; INF and NAN have no PHP literal.', $at);

            return null;
        }

        $read = $this->read($default, $inner, $schema);
        if (!DefaultFit::fits($read->value(), $inner)) {
            $diagnostics->warning(
                sprintf(
                    'Default %s does not match %s; null is used instead.',
                    json_encode($default->value(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE),
                    $inner->describe(),
                ),
                $at,
            );

            return null;
        }

        return $read;
    }

    /**
     * A default written as a string for a boolean or a number (`default: "true"`, Xero) is the value it spells; a whole
     * float is the int of a number its format made one (`type: number, format: int32, default: 1.0`). An `integer`
     * keeps refusing `2.0`.
     */
    private function read(DefaultValue $default, TypeModel $type, Schema $schema): DefaultValue
    {
        $value = $default->value();
        $kind = $type instanceof ScalarType ? $type->kind() : null;
        $number = in_array(SchemaType::NUMBER, array_map(static fn (SchemaType $declared): string => $declared->value(), $schema->nonNullTypes()), true);
        // Range first: PHP 8.5 warns about casting a float int cannot hold. PHP_INT_MAX compares as 2^63, outside.
        if ($kind === 'int' && $number && is_float($value) && $value >= PHP_INT_MIN && $value < PHP_INT_MAX && (float) (int) $value === $value) {
            return new DefaultValue((int) $value);
        }

        if (!is_string($value)) {
            return $default;
        }

        // Only what JSON would have written without the quotes: no spaces, no leading zeros, no `+`.
        if ($kind === 'bool' && in_array($value, ['true', 'false'], true)) {
            return new DefaultValue($value === 'true');
        }

        $int = filter_var($value, FILTER_VALIDATE_INT);
        if ($kind === 'int' && is_int($int) && preg_match('/^-?(?:0|[1-9][0-9]*)$/D', $value) === 1) {
            return new DefaultValue($int);
        }

        return $kind === 'float' && preg_match(DefaultFit::JSON_NUMBER, $value) === 1 && is_finite((float) $value) ? new DefaultValue((float) $value) : $default;
    }

    /**
     * The class or map type, possibly nested in lists, that no PHP constant expression can produce.
     *
     * @return ClassType|MapType|null
     */
    private static function unrepresentable(TypeModel $type): ?TypeModel
    {
        if ($type instanceof ClassType || $type instanceof MapType) {
            return $type;
        }

        if ($type instanceof NullableType) {
            return self::unrepresentable($type->inner());
        }

        return $type instanceof ListType ? self::unrepresentable($type->item()) : null;
    }

    /**
     * @param JsonValue $value
     */
    private static function containsNonFiniteFloat($value): bool
    {
        if (is_float($value)) {
            return !is_finite($value);
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                if (self::containsNonFiniteFloat(Json::value($item))) {
                    return true;
                }
            }
        }

        return false;
    }

    private function mutability(Schema $schema, Diagnostics $diagnostics): Mutability
    {
        $default = $this->target->mutability();
        if (!$schema->extensions()->has('x-dto-mutable')) {
            return $default;
        }

        $at = $schema->location()->child('x-dto-mutable');
        $value = $schema->extensions()->get('x-dto-mutable');
        if (!is_bool($value)) {
            $diagnostics->error('"x-dto-mutable" must be true or false.', $at);

            return $default;
        }

        $mutability = Mutability::from($value ? Mutability::MUTABLE : Mutability::IMMUTABLE);
        try {
            $this->target->accessorsFor($mutability);
        } catch (IncompatibleTarget $exception) {
            $diagnostics->error($exception->getMessage(), $at);

            return $default;
        }

        return $mutability;
    }
}
