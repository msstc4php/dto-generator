<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Support;

use MSSTC4PHP\DtoGenerator\Domain\Model\ArgumentValue;
use MSSTC4PHP\DtoGenerator\Domain\Model\AttributeArgument;
use MSSTC4PHP\DtoGenerator\Domain\Model\AttributeModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;

/**
 * Attributes written PHP-like, so tests compare whole attribute trees in one line.
 */
final class AttributeFixture
{
    public static function describe(AttributeModel $attribute): string
    {
        return $attribute->className()->fqcn() . '(' . self::arguments($attribute->arguments()) . ')';
    }

    /**
     * @param list<AttributeArgument> $arguments
     */
    private static function arguments(array $arguments): string
    {
        return implode(', ', array_map(
            static fn (AttributeArgument $argument): string => ($argument->isNamed() ? $argument->name() . ': ' : '') . self::value($argument->value()),
            $arguments,
        ));
    }

    private static function value(ArgumentValue $value): string
    {
        switch ($value->kind()) {
            case ArgumentValue::KIND_LITERAL:
                return var_export($value->literalValue(), true);
            case ArgumentValue::KIND_LIST:
                return '[' . implode(', ', array_map([self::class, 'value'], $value->listItems())) . ']';
            case ArgumentValue::KIND_MAP:
                $items = [];
                foreach ($value->mapItems() as $key => $item) {
                    $items[] = var_export($key, true) . ' => ' . self::value($item);
                }

                return '[' . implode(', ', $items) . ']';
            case ArgumentValue::KIND_CONSTANT:
                $class = $value->constantClass();

                return ($class instanceof ClassName ? $class->fqcn() . '::' : '') . $value->constantName();
            case ArgumentValue::KIND_CLASS_REFERENCE:
                return $value->className()->fqcn() . '::class';
            default:
                return 'new ' . $value->className()->fqcn() . '(' . self::arguments($value->arguments()) . ')';
        }
    }
}
