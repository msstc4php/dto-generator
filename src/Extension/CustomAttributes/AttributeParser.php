<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Extension\CustomAttributes;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ArgumentValue;
use MSSTC4PHP\DtoGenerator\Domain\Model\AttributeArgument;
use MSSTC4PHP\DtoGenerator\Domain\Model\AttributeModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;

/**
 * The attribute grammar of spec §7.1: `{class, args}`, where a map of args names them and a list passes them in order,
 * and a map with the single key `const`, `class`, `new` or `literal` is a marker rather than an array.
 *
 * @phpstan-import-type JsonValue from Json
 */
final class AttributeParser
{
    private const MARKERS = ['const', 'class', 'new', 'literal'];

    /**
     * @param JsonValue $declaration
     *
     * @throws GrammarError
     */
    public function attribute($declaration, SchemaLocation $at): AttributeModel
    {
        [$class, $arguments] = $this->call($declaration, $at, 'An attribute must be an object with "class" and optional "args".');

        try {
            return new AttributeModel($class, $arguments);
        } catch (InvalidModel $exception) {
            throw new GrammarError($exception->getMessage(), $at->child('args'));
        }
    }

    /**
     * `{class, args?}`, the shape of an attribute and of `new`.
     *
     * @param JsonValue $declaration
     *
     * @return array{ClassName, list<AttributeArgument>}
     */
    private function call($declaration, SchemaLocation $at, string $shape): array
    {
        if (!is_array($declaration) || Json::isList($declaration) || !array_key_exists('class', $declaration)) {
            throw new GrammarError($shape, $at);
        }

        foreach (array_keys($declaration) as $key) {
            if ($key !== 'class' && $key !== 'args') {
                throw new GrammarError(sprintf('Unknown key "%s"; an attribute takes "class" and "args".', $key), $at->child((string) $key));
            }
        }

        return [$this->className(Json::value($declaration['class']), $at->child('class')), $this->arguments(Json::value($declaration['args'] ?? []), $at->child('args'))];
    }

    /**
     * @param JsonValue $args
     *
     * @return list<AttributeArgument>
     */
    private function arguments($args, SchemaLocation $at): array
    {
        if (!is_array($args)) {
            throw new GrammarError('"args" must be a list or an object.', $at);
        }

        $arguments = [];
        foreach ($args as $name => $value) {
            $value = $this->value(Json::value($value), $at->child((string) $name));
            if (is_int($name)) {
                $arguments[] = AttributeArgument::positional($value);

                continue;
            }

            try {
                $arguments[] = AttributeArgument::named($name, $value);
            } catch (InvalidModel $exception) {
                throw new GrammarError($exception->getMessage(), $at->child($name));
            }
        }

        return $arguments;
    }

    /**
     * @param JsonValue $value
     */
    private function value($value, SchemaLocation $at): ArgumentValue
    {
        if (!is_array($value)) {
            return ArgumentValue::literal($value);
        }

        if (Json::isList($value)) {
            $items = [];
            foreach ($value as $index => $item) {
                $items[] = $this->value(Json::value($item), $at->child((string) $index));
            }

            return ArgumentValue::listOf(...$items);
        }

        $marker = array_key_first($value);
        if (count($value) !== 1 || !in_array($marker, self::MARKERS, true)) {
            return $this->map($value, $at);
        }

        return $this->marker($marker, Json::value($value[$marker]), $at->child($marker));
    }

    /**
     * @param 'const'|'class'|'new'|'literal' $marker
     * @param JsonValue $value
     */
    private function marker(string $marker, $value, SchemaLocation $at): ArgumentValue
    {
        switch ($marker) {
            case 'const':
                return $this->constant($value, $at);
            case 'class':
                return ArgumentValue::classReference($this->className($value, $at));
            case 'new':
                [$class, $arguments] = $this->call($value, $at, '"new" must be an object with "class" and optional "args".');
                try {
                    return ArgumentValue::newInstance($class, ...$arguments);
                } catch (InvalidModel $exception) {
                    throw new GrammarError($exception->getMessage(), $at->child('args'));
                }
            default:
                if (!is_array($value) || ($value !== [] && Json::isList($value))) {
                    throw new GrammarError('"literal" must be an object.', $at);
                }

                return $this->map($value, $at);
        }
    }

    /**
     * @param array<array-key, mixed> $value
     */
    private function map(array $value, SchemaLocation $at): ArgumentValue
    {
        $items = [];
        foreach ($value as $key => $item) {
            $items[$key] = $this->value(Json::value($item), $at->child((string) $key));
        }

        return ArgumentValue::mapOf($items);
    }

    /**
     * @param JsonValue $value
     */
    private function constant($value, SchemaLocation $at): ArgumentValue
    {
        if (!is_string($value) || $value === '') {
            throw new GrammarError('"const" must be a constant name like "App\Mask::TAIL".', $at);
        }

        $separator = strrpos($value, '::');
        $name = $separator === false ? $value : substr($value, $separator + 2);
        if ($name === '') {
            throw new GrammarError('"const" must be a constant name like "App\Mask::TAIL".', $at);
        }

        if ($separator > 0 && strcasecmp($name, 'class') === 0) {
            $class = substr($value, 0, $separator);

            throw new GrammarError(sprintf('"const" names a constant; for the name of class %s use {class: %s}.', $class, $class), $at);
        }

        try {
            return ArgumentValue::constant($name, $separator === false ? null : ClassName::fromFqcn(substr($value, 0, $separator)));
        } catch (InvalidModel $exception) {
            throw new GrammarError($exception->getMessage(), $at);
        }
    }

    /**
     * @param JsonValue $value
     */
    private function className($value, SchemaLocation $at): ClassName
    {
        if (!is_string($value)) {
            throw new GrammarError('"class" must be a class name.', $at);
        }

        try {
            return ClassName::fromFqcn($value);
        } catch (InvalidModel $exception) {
            throw new GrammarError($exception->getMessage(), $at);
        }
    }
}
