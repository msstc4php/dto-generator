<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Infrastructure\Emitter;

use MSSTC4PHP\DtoGenerator\Domain\Model\AttributeModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\Identifier;
use MSSTC4PHP\DtoGenerator\Domain\Model\ImportAlias;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Use_;
use PhpParser\Node\UseItem;

/**
 * How the attributes or annotations of one file name their classes, and the `use … as` lines their import aliases need.
 */
final class AttributeNames
{
    private TypeRenderer $types;

    /** @var array<string, ImportAlias> */
    private array $imports = [];

    /** @var array<string, string> lower-cased aliases written in full instead */
    private array $refused = [];

    /**
     * @param list<string> $refused import aliases that collide with a name of the file, in any case; their attributes are
     *                              written in full
     */
    public function __construct(TypeRenderer $types, array $refused = [])
    {
        $this->types = $types;
        foreach ($refused as $alias) {
            $this->refused[Identifier::asciiLower($alias)] = $alias;
        }
    }

    public function name(AttributeModel $attribute): Name
    {
        $alias = $attribute->importAlias();
        if (!$alias instanceof ImportAlias || isset($this->refused[Identifier::asciiLower($alias->alias())])) {
            return $this->types->nameOf($attribute->className());
        }

        $this->imports[Identifier::asciiLower($alias->alias())] = $alias;

        return new Name($alias->alias() . substr($attribute->className()->fqcn(), strlen($alias->namespace())));
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
     * @return list<Use_>
     */
    public function uses(): array
    {
        return array_map(
            static fn (ImportAlias $alias): Use_ => new Use_([new UseItem(new Name($alias->namespace()), $alias->alias())]),
            array_values($this->imports),
        );
    }
}
