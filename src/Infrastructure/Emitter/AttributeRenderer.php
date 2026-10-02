<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Infrastructure\Emitter;

use MSSTC4PHP\DtoGenerator\Domain\Model\ArgumentValue;
use MSSTC4PHP\DtoGenerator\Domain\Model\AttributeArgument;
use MSSTC4PHP\DtoGenerator\Domain\Model\AttributeModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Model\Identifier;
use MSSTC4PHP\DtoGenerator\Domain\Model\ImportAlias;
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
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Stmt\Use_;
use PhpParser\Node\UseItem;

/**
 * PHP 8 attributes of one file (spec §7.1), each in its own `#[...]`. Collects the `use … as` lines that the
 * attributes with an import alias need.
 */
final class AttributeRenderer
{
    private TypeRenderer $types;

    private BuilderFactory $factory;

    private bool $enabled;

    /** @var array<string, ImportAlias> */
    private array $imports = [];

    /**
     * @param bool $enabled false for a target without attribute metadata, which renders none
     */
    /** @var array<string, string> lower-cased aliases written in full instead */
    private array $refused = [];

    /**
     * @param list<string> $refused import aliases that collide with a name of the file; their attributes are written in full
     */
    public function __construct(TypeRenderer $types, BuilderFactory $factory, bool $enabled, array $refused = [])
    {
        $this->types = $types;
        $this->factory = $factory;
        $this->enabled = $enabled;
        foreach ($refused as $alias) {
            $this->refused[$alias] = $alias;
        }
    }

    /**
     * The imported aliases that are also a short name of the file (PHP compares them without case).
     *
     * @param string $className the short name of the class the file declares
     *
     * @return list<string> lower-cased
     */
    public function collisions(string $className): array
    {
        $taken = $this->types->shortNames();
        $taken[Identifier::asciiLower($className)] = $className;
        $collisions = [];
        foreach (array_keys($this->imports) as $alias) {
            if (isset($taken[$alias])) {
                $collisions[] = $alias;
            }
        }

        return $collisions;
    }

    /**
     * @param list<AttributeModel> $attributes
     *
     * @return list<AttributeGroup>
     */
    public function groups(array $attributes): array
    {
        if (!$this->enabled) {
            return [];
        }

        return array_map(
            fn (AttributeModel $attribute): AttributeGroup => new AttributeGroup([new Attribute($this->name($attribute), $this->arguments($attribute->arguments()))]),
            $attributes,
        );
    }

    /**
     * @return list<Use_>
     */
    public function uses(): array
    {
        return array_map(
            static fn (ImportAlias $alias): Use_ => new Use_([new UseItem(new Name($alias->namespace()), $alias->alias())]),
            array_values($this->imports),
        );
    }

    private function name(AttributeModel $attribute): Name
    {
        $alias = $attribute->importAlias();
        if (!$alias instanceof ImportAlias || isset($this->refused[Identifier::asciiLower($alias->alias())])) {
            return $this->types->nameOf($attribute->className());
        }

        $this->imports[Identifier::asciiLower($alias->alias())] = $alias;

        return new Name($alias->alias() . substr($attribute->className()->fqcn(), strlen($alias->namespace())));
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
