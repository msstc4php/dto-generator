<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Target;

use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Model\ArgumentValue;
use MSSTC4PHP\DtoGenerator\Domain\Model\AttributeArgument;
use MSSTC4PHP\DtoGenerator\Domain\Model\AttributeModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Model\Identifier;
use MSSTC4PHP\DtoGenerator\Domain\Model\ImportAlias;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;

/**
 * What the target's PHP version allows in attributes (spec §6.1, §7.1): the emitter has no channel for diagnostics.
 *
 * @api
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
        if ($target->metadata()->isAnnotations()) {
            return self::admittedAsAnnotations($attributes, $target, $at, $diagnostics);
        }

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
     * Doctrine annotations have one "value" for all positional arguments and strings without escapes (spec §7.1): an
     * argument list that has both is refused like `new` below 8.1, the lossy rest is a warning.
     *
     * @param list<AttributeModel> $attributes
     *
     * @return list<AttributeModel>
     */
    private static function admittedAsAnnotations(array $attributes, TargetProfile $target, SchemaLocation $at, Diagnostics $diagnostics): array
    {
        $admitted = [];
        foreach ($attributes as $attribute) {
            $name = $attribute->className();
            $lists = self::argumentLists($name, $attribute->arguments());
            $conflict = self::valueConflict($lists);
            if ($conflict instanceof ClassName) {
                $message = $conflict->fqcn() === $name->fqcn()
                    ? sprintf('Attribute %s has both a positional argument and a named "value", which one annotation cannot hold', $name->fqcn())
                    : sprintf('Attribute %s passes %s both a positional argument and a named "value", which one annotation cannot hold', $name->fqcn(), $conflict->fqcn());
                if ($target->isStrict()) {
                    $diagnostics->error($message . '.', $at);
                } else {
                    $diagnostics->warning($message . '; it is left out.', $at);
                }

                continue;
            }

            foreach ($lists as [$holder, $arguments]) {
                $positional = count(array_filter($arguments, static fn (AttributeArgument $argument): bool => !$argument->isNamed()));
                if ($positional > 1) {
                    $diagnostics->warning(sprintf('Attribute %s passes %d positional arguments; an annotation collects them into one list under "value".', $holder->fqcn(), $positional), $at);
                }
            }

            $values = array_map(static fn (AttributeArgument $argument): ArgumentValue => $argument->value(), $attribute->arguments());
            if (self::hasEscapedString(ArgumentValue::listOf(...$values))) {
                $diagnostics->warning(sprintf('Attribute %s has a string with a line break or "*/"; an annotation writes them as the characters "\\n" and "*\\/".', $name->fqcn()), $at);
            }

            $admitted[] = $attribute;
        }

        return $admitted;
    }

    /**
     * The argument lists of an attribute and of every `new` within it.
     *
     * @param list<AttributeArgument> $arguments
     *
     * @return list<array{ClassName, list<AttributeArgument>}>
     */
    private static function argumentLists(ClassName $holder, array $arguments): array
    {
        $lists = [[$holder, $arguments]];
        foreach ($arguments as $argument) {
            foreach (self::newInstances($argument->value()) as $new) {
                $lists = array_merge($lists, self::argumentLists($new->className(), $new->arguments()));
            }
        }

        return $lists;
    }

    /**
     * @return list<ArgumentValue> the `new` values in a value, not looking into their own arguments
     */
    private static function newInstances(ArgumentValue $value): array
    {
        if ($value->kind() === ArgumentValue::KIND_NEW_INSTANCE) {
            return [$value];
        }

        $found = [];
        foreach ($value->children() as $child) {
            $found = array_merge($found, self::newInstances($child));
        }

        return $found;
    }

    /**
     * @param list<array{ClassName, list<AttributeArgument>}> $lists
     *
     * @return ClassName|null the holder of the first list with a positional argument and a named "value"
     */
    private static function valueConflict(array $lists): ?ClassName
    {
        foreach ($lists as [$holder, $arguments]) {
            $positional = false;
            $value = false;
            foreach ($arguments as $argument) {
                $positional = $positional || !$argument->isNamed();
                $value = $value || $argument->name() === 'value';
            }

            if ($positional && $value) {
                return $holder;
            }
        }

        return null;
    }

    private static function hasEscapedString(ArgumentValue $value): bool
    {
        if ($value->kind() === ArgumentValue::KIND_LITERAL) {
            $literal = $value->literalValue();

            return is_string($literal) && self::isEscaped($literal);
        }

        if ($value->kind() === ArgumentValue::KIND_MAP) {
            foreach (array_keys($value->mapItems()) as $key) {
                if (is_string($key) && self::isEscaped($key)) {
                    return true;
                }
            }
        }

        foreach ($value->children() as $child) {
            if (self::hasEscapedString($child)) {
                return true;
            }
        }

        return false;
    }

    private static function isEscaped(string $text): bool
    {
        return strpbrk($text, "\r\n") !== false || strpos($text, '*/') !== false;
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
        if ($value->kind() === ArgumentValue::KIND_NEW_INSTANCE) {
            return true;
        }

        foreach ($value->children() as $child) {
            if (self::containsNew($child)) {
                return true;
            }
        }

        return false;
    }
}
