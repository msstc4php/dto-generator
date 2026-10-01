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
use MSSTC4PHP\DtoGenerator\Domain\Model\MapType;
use MSSTC4PHP\DtoGenerator\Domain\Model\NullableType;
use MSSTC4PHP\DtoGenerator\Domain\Model\PropertyModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\TypeModel;
use MSSTC4PHP\DtoGenerator\Domain\Schema\ResolvedSchema;
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

    public function __construct(NameResolver $names, TypeMapper $types, TargetProfile $target)
    {
        $this->names = $names;
        $this->types = $types;
        $this->target = $target;
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

    public function build(ClassName $name, ResolvedSchema $resolved, Diagnostics $diagnostics): ClassModel
    {
        $schema = $resolved->schema();

        $properties = [];
        $taken = [];
        foreach ($schema->propertyNames() as $wireName) {
            $propertySchema = $schema->requireProperty($wireName);
            $this->checkExtensions($propertySchema, $diagnostics);
            if (self::isSkipped($propertySchema, $diagnostics)) {
                if ($schema->isRequired($wireName)) {
                    $diagnostics->warning(
                        sprintf('Property "%s" is required but excluded by "x-php-skip".', $wireName),
                        $propertySchema->location()->child('x-php-skip'),
                    );
                }

                continue;
            }

            $property = $this->property($schema, $wireName, $propertySchema, $diagnostics);
            if (!$property instanceof PropertyModel) {
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
            $properties[] = $property;
        }

        return new ClassModel(
            $name,
            ClassKind::from(ClassKind::FINAL),
            null,
            $properties,
            $this->mutability($schema, $diagnostics),
            new DocModel($schema->description(), $schema->isDeprecated()),
            $schema->location(),
        );
    }

    private function property(Schema $owner, string $wireName, Schema $schema, Diagnostics $diagnostics): ?PropertyModel
    {
        $name = $this->propertyName($wireName, $schema, $diagnostics);
        if ($name === null) {
            return null;
        }

        $type = $this->types->map($schema, $diagnostics);
        $required = $owner->isRequired($wireName) && !$type instanceof NullableType;
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
            if (is_string($override) && Identifier::isValid($override) && $override !== 'this') {
                return $override;
            }

            $diagnostics->error('"x-php-name" must be a PHP identifier other than "this".', $schema->location()->child('x-php-name'));
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
        if ($inner instanceof ClassType) {
            $diagnostics->warning(sprintf('A default for %s cannot be a PHP constant expression; null is used instead.', $inner->describe()), $at);

            return null;
        }

        if ($inner instanceof MapType) {
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
                    json_encode($default->value(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION),
                    $inner->describe(),
                ),
                $at,
            );

            return null;
        }

        return $default;
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

    private function checkExtensions(Schema $property, Diagnostics $diagnostics): void
    {
        ExtensionVocabulary::check($property, ExtensionVocabulary::PROPERTY, $diagnostics);
        for ($items = $property->items(); $items instanceof Schema; $items = $items->items()) {
            ExtensionVocabulary::check($items, ExtensionVocabulary::ITEMS, $diagnostics);
        }
    }
}
