<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Target;

use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Model\ArgumentValue;
use MSSTC4PHP\DtoGenerator\Domain\Model\AttributeModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\Identifier;
use MSSTC4PHP\DtoGenerator\Domain\Model\ImportAlias;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;

/**
 * What the target's PHP version allows in attributes (spec §6.1, §7.1): the emitter has no channel for diagnostics.
 */
final class AttributeRules
{
    private function __construct()
    {
    }

    /**
     * The attributes the target can render; the others are reported, as errors when strict (spec §6.1), and left out.
     *
     * @param list<AttributeModel> $attributes
     *
     * @return list<AttributeModel>
     */
    public static function admitted(array $attributes, TargetProfile $target, SchemaLocation $at, Diagnostics $diagnostics): array
    {
        if (!$target->metadata()->isAttributes() || $target->supports(Capability::from(Capability::NEW_IN_INITIALIZERS))) {
            return $attributes;
        }

        $admitted = [];
        foreach ($attributes as $attribute) {
            if (!self::usesNew($attribute)) {
                $admitted[] = $attribute;

                continue;
            }

            $message = sprintf(
                'Attribute %s uses "new" in its arguments, which PHP %s does not allow (from 8.1)',
                $attribute->className()->fqcn(),
                $target->php()->toString(),
            );
            if ($target->isStrict()) {
                $diagnostics->error($message . '.', $at);
            } else {
                $diagnostics->warning($message . '; it is left out.', $at);
            }
        }

        return $admitted;
    }

    /**
     * One file has one `use … as Alias` per alias, so the class and its own properties must agree on each.
     */
    public static function checkImportAliases(ClassModel $class, Diagnostics $diagnostics): void
    {
        // PHP compares namespaces and aliases without case, so "Assert" and "assert" are one alias.
        $namespaces = [];
        foreach (self::all($class) as $attribute) {
            $alias = $attribute->importAlias();
            if (!$alias instanceof ImportAlias) {
                continue;
            }

            $key = Identifier::asciiLower($alias->alias());
            $known = $namespaces[$key] ?? null;
            if ($known !== null && Identifier::asciiLower($known) !== Identifier::asciiLower($alias->namespace())) {
                $diagnostics->error(
                    sprintf('Import alias "%s" stands for both %s and %s in %s.', $alias->alias(), $known, $alias->namespace(), $class->name()->fqcn()),
                    $class->source(),
                );
            }

            $namespaces[$key] = $alias->namespace();
        }
    }

    /**
     * @return list<AttributeModel>
     */
    private static function all(ClassModel $class): array
    {
        $attributes = $class->attributes();
        foreach ($class->properties() as $property) {
            $attributes = array_merge($attributes, $property->attributes());
        }

        return $attributes;
    }

    private static function usesNew(AttributeModel $attribute): bool
    {
        foreach ($attribute->arguments() as $argument) {
            if (self::containsNew($argument->value())) {
                return true;
            }
        }

        return false;
    }

    private static function containsNew(ArgumentValue $value): bool
    {
        switch ($value->kind()) {
            case ArgumentValue::KIND_NEW_INSTANCE:
                return true;
            case ArgumentValue::KIND_LIST:
                $items = $value->listItems();

                break;
            case ArgumentValue::KIND_MAP:
                $items = $value->mapItems();

                break;
            default:
                return false;
        }

        foreach ($items as $item) {
            if (self::containsNew($item)) {
                return true;
            }
        }

        return false;
    }
}
