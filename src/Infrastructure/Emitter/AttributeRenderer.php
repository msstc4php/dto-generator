<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Infrastructure\Emitter;

use MSSTC4PHP\DtoGenerator\Domain\Model\ArgumentValue;
use MSSTC4PHP\DtoGenerator\Domain\Model\AttributeArgument;
use MSSTC4PHP\DtoGenerator\Domain\Model\AttributeModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Target\MetadataMode;
use PhpParser\BuilderFactory;
use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Attribute;
use PhpParser\Node\AttributeGroup;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Identifier as NodeIdentifier;
use PhpParser\Node\Name\FullyQualified;

/**
 * PHP 8 attributes of one file (spec §7.1), each in its own `#[...]`.
 */
final class AttributeRenderer
{
    private TypeRenderer $types;

    private BuilderFactory $factory;

    private AttributeNames $names;

    private MetadataMode $mode;

    public function __construct(TypeRenderer $types, BuilderFactory $factory, AttributeNames $names, MetadataMode $mode)
    {
        $this->types = $types;
        $this->factory = $factory;
        $this->names = $names;
        $this->mode = $mode;
    }

    /**
     * @param list<AttributeModel> $attributes
     *
     * @return list<AttributeGroup>
     */
    public function groups(array $attributes): array
    {
        if (!$this->mode->isAttributes()) {
            return [];
        }

        return array_map(
            fn (AttributeModel $attribute): AttributeGroup => new AttributeGroup([new Attribute($this->names->name($attribute), $this->arguments($attribute->arguments()))]),
            $attributes,
        );
    }

    /**
     * @param list<AttributeArgument> $arguments
     *
     * @return list<Arg>
     */
    private function arguments(array $arguments): array
    {
        $args = [];
        foreach ($arguments as $argument) {
            $name = $argument->name();
            $args[] = new Arg($this->value($argument->value()), false, false, [], $name === null ? null : new NodeIdentifier($name));
        }

        return $args;
    }

    private function value(ArgumentValue $value): Expr
    {
        switch ($value->kind()) {
            case ArgumentValue::KIND_LITERAL:
                return ReadableLiteral::of($this->factory->val($value->literalValue()));
            case ArgumentValue::KIND_LIST:
                return new Array_(array_map(fn (ArgumentValue $item): ArrayItem => new ArrayItem($this->value($item)), $value->listItems()));
            case ArgumentValue::KIND_MAP:
                $items = [];
                foreach ($value->mapItems() as $key => $item) {
                    $items[] = new ArrayItem($this->value($item), ReadableLiteral::of($this->factory->val($key)));
                }

                return new Array_($items);
            case ArgumentValue::KIND_CONSTANT:
                $class = $value->constantClass();

                return $class instanceof ClassName
                    ? new ClassConstFetch($this->types->nameOf($class), $value->constantName())
                    : new ConstFetch(new FullyQualified($value->constantName()));
            case ArgumentValue::KIND_CLASS_REFERENCE:
                return new ClassConstFetch($this->types->nameOf($value->className()), 'class');
            default:
                return new New_($this->types->nameOf($value->className()), $this->arguments($value->arguments()));
        }
    }
}
