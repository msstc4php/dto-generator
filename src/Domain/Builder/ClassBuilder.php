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
use MSSTC4PHP\DtoGenerator\Domain\Model\TypeModel;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;
use MSSTC4PHP\DtoGenerator\Domain\Shared\DefaultValue;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;
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

    private TypeMapper $types;

    private TargetProfile $target;

    /** @var list<string> */
    private array $aliases;

    /**
     * @param list<string> $aliases keys of attributeAliases, which properties may carry
     */
    public function __construct(NameResolver $names, TypeMapper $types, TargetProfile $target, array $aliases = [])
    {
        $this->names = $names;
        $this->types = $types;
        $this->target = $target;
        $this->aliases = $aliases;
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
        $sources = $this->sources($composition);
        $skipped = $this->skippedWireNames($sources);
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

            $property = $this->property($wireName, $propertySchema, $composition->isRequired($wireName), $diagnostics);
            if (!$property instanceof PropertyModel) {
                continue;
            }

            $earlier = $byWireName[$wireName] ?? null;
            if ($earlier instanceof PropertyModel) {
                if ($earlier->type()->describe() !== $property->type()->describe()) {
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
                }

                continue;
            }

            // Accessors are case-insensitive in PHP, so $url and $URL would both declare getUrl().
            $key = Identifier::asciiLower($property->name());
            if (isset($taken[$key])) {
                $diagnostics->error(
                    sprintf('Property "%s" becomes $%s, which "%s" already uses; set "x-php-name" on one of them.', $wireName, $property->name(), $taken[$key]),
                    $propertySchema->location(),
                );

                continue;
            }

            $taken[$key] = $wireName;
            $byWireName[$wireName] = $property;
            $properties[] = $property;
        }

        $additional = $this->additionalProperties($schema, isset($taken['additionalproperties']), $diagnostics);
        if ($additional instanceof PropertyModel) {
            $properties[] = $additional;
        }

        return new ClassModel(
            $name,
            ClassKind::from(ClassKind::FINAL),
            $composition->parent(),
            $properties,
            $this->mutability($schema, $diagnostics),
            new DocModel($schema->description(), $schema->isDeprecated()),
            $schema->location(),
        );
    }

    /**
     * `properties` together with an `additionalProperties` schema: the extra keys land in $additionalProperties (spec §5.1).
     */
    private function additionalProperties(Schema $schema, bool $nameTaken, Diagnostics $diagnostics): ?PropertyModel
    {
        $additional = $schema->additionalProperties();
        if (!$additional instanceof Schema) {
            return null;
        }

        if ($nameTaken) {
            $diagnostics->error(
                'The class already has a property $additionalProperties; rename it with "x-php-name".',
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
     * A property any merged member excludes stays excluded, whichever member declares it first.
     *
     * @param list<array{string, Schema}> $sources
     *
     * @return array<string, string>
     */
    private function skippedWireNames(array $sources): array
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
     * The default as JSON writes it: `1` and `1.0` of a number are the same default.
     */
    private function defaultOf(PropertyModel $property): string
    {
        $default = $property->default();

        return $default instanceof DefaultValue ? $default->toJson() : '';
    }

    /**
     * @return list<array{string, Schema}> wire name and schema of every property, part by part
     */
    private function sources(Composition $composition): array
    {
        $sources = [];
        foreach ($composition->parts() as $part) {
            foreach ($part->propertyNames() as $wireName) {
                $sources[] = [$wireName, $part->requireProperty($wireName)];
            }
        }

        return $sources;
    }

    private function property(string $wireName, Schema $schema, bool $required, Diagnostics $diagnostics): ?PropertyModel
    {
        $name = $this->propertyName($wireName, $schema, $diagnostics);
        if ($name === null) {
            return null;
        }

        $type = $this->types->map($schema, $diagnostics);
        $required = $required && !$type instanceof NullableType;
        $default = null;
        if (!$required) {
            $type = TypeMapper::nullable($type);
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

    private function propertyName(string $wireName, Schema $schema, Diagnostics $diagnostics): ?string
    {
        if ($schema->extensions()->has('x-php-name')) {
            $override = $schema->extensions()->get('x-php-name');
            if (is_string($override) && Identifier::isValid($override) && $override !== 'this' && !Identifier::isSuperglobal($override)) {
                return $override;
            }

            $diagnostics->error('"x-php-name" must be a PHP identifier other than "this" and the superglobals.', $schema->location()->child('x-php-name'));
        }

        $name = $this->names->propertyName($wireName);
        if ($name === null) {
            $diagnostics->error(sprintf('Property name "%s" has no usable characters; set "x-php-name".', $wireName), $schema->location());
        }

        return $name;
    }

    private function defaultFor(Schema $schema, TypeModel $type, Diagnostics $diagnostics): ?DefaultValue
    {
        $default = $schema->default();
        if (!$default instanceof DefaultValue || $default->value() === null) {
            return $default;
        }

        $at = $schema->location()->child('default');
        $inner = $type instanceof NullableType ? $type->inner() : $type;
        // Only a non-empty list reaches its item type; any other value of a list is checked as a plain mismatch.
        $value = $default->value();
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

        if (!DefaultFit::fits($default->value(), $inner)) {
            $diagnostics->error(
                sprintf(
                    'Default %s does not match %s; null is used instead.',
                    json_encode($default->value(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE),
                    $inner->describe(),
                ),
                $at,
            );

            return null;
        }

        return $default;
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
