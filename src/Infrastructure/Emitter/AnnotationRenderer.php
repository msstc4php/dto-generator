<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Infrastructure\Emitter;

use MSSTC4PHP\DtoGenerator\Domain\Model\ArgumentValue;
use MSSTC4PHP\DtoGenerator\Domain\Model\AttributeArgument;
use MSSTC4PHP\DtoGenerator\Domain\Model\AttributeModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;
use MSSTC4PHP\DtoGenerator\Domain\Target\MetadataMode;

/**
 * Doctrine-style docblock annotations of one file (spec §7.1), the form attributes take below PHP 8.0.
 *
 * @phpstan-import-type JsonScalar from Json
 */
final class AnnotationRenderer
{
    // The width of the annotation text itself; the docblock prefix and indentation come on top.
    private const TEXT_WIDTH = 100;

    private TypeRenderer $types;

    private AttributeNames $names;

    private MetadataMode $mode;

    public function __construct(TypeRenderer $types, AttributeNames $names, MetadataMode $mode)
    {
        $this->types = $types;
        $this->names = $names;
        $this->mode = $mode;
    }

    /**
     * @param list<AttributeModel> $attributes
     *
     * @return list<string> docblock lines; a long annotation spans several
     */
    public function annotations(array $attributes): array
    {
        if (!$this->mode->isAnnotations()) {
            return [];
        }

        $lines = [];
        foreach ($attributes as $attribute) {
            $node = $this->call('@' . $this->names->name($attribute)->toCodeString(), $attribute->arguments());
            array_push($lines, ...$node->lines(self::TEXT_WIDTH));
        }

        return $lines;
    }

    /**
     * Doctrine passes one default value: a lone positional argument is it, several form a list under "value".
     *
     * @param list<AttributeArgument> $arguments
     */
    private function call(string $name, array $arguments): AnnotationNode
    {
        $positional = [];
        $named = [];
        foreach ($arguments as $argument) {
            $key = $argument->name();
            $value = $this->value($argument->value());
            if ($key === null) {
                $positional[] = $value;
            } else {
                $named[] = $value->after($key . '=');
            }
        }

        if (count($positional) > 1) {
            $positional = [AnnotationNode::group('value={', $positional, '}')];
        }

        $items = array_merge($positional, $named);

        return $items === [] ? AnnotationNode::leaf($name) : AnnotationNode::group($name . '(', $items, ')');
    }

    private function value(ArgumentValue $value): AnnotationNode
    {
        switch ($value->kind()) {
            case ArgumentValue::KIND_LITERAL:
                return AnnotationNode::leaf($this->literal($value->literalValue()));
            case ArgumentValue::KIND_LIST:
                return AnnotationNode::group('{', array_map([$this, 'value'], $value->listItems()), '}');
            case ArgumentValue::KIND_MAP:
                $items = [];
                foreach ($value->mapItems() as $key => $item) {
                    $items[] = $this->value($item)->after($this->literal($key) . '=');
                }

                return AnnotationNode::group('{', $items, '}');
            case ArgumentValue::KIND_CONSTANT:
                $class = $value->constantClass();

                return AnnotationNode::leaf(($class instanceof ClassName ? $this->fullName($class) . '::' : '') . $value->constantName());
            case ArgumentValue::KIND_CLASS_REFERENCE:
                return AnnotationNode::leaf($this->fullName($value->className()) . '::class');
            default:
                return $this->call('@' . $this->types->nameOf($value->className())->toCodeString(), $value->arguments());
        }
    }

    /**
     * Doctrine resolves a short name in `X::class` and `X::C` only when the class loads at parse time, and then may
     * still return it unqualified; a fully qualified name needs no resolution.
     */
    private function fullName(ClassName $class): string
    {
        return '\\' . $class->fqcn();
    }

    /**
     * Doctrine strings double their quotes and have no escapes; a line break or "*\/" would end the docblock line or
     * the docblock, so they become the two characters "\n" and "*\/" (Enrich warns about it).
     *
     * @param JsonScalar $value
     */
    private function literal($value): string
    {
        if (is_string($value)) {
            return '"' . str_replace(['"', "\r\n", "\r", "\n", '*/'], ['""', '\n', '\n', '\n', '*\/'], $value) . '"';
        }

        // var_export() keeps a float a float, like 2.0.
        return $value === null ? 'null' : var_export($value, true);
    }
}
