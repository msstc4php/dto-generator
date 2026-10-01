<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Model;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;

/**
 * `use <namespace> as <alias>;` — e.g. Symfony constraints as `Assert`, which annotations rely on.
 */
final class ImportAlias
{
    private string $namespace;

    private string $alias;

    public function __construct(string $namespace, string $alias)
    {
        if (!Identifier::isValid($alias) || Identifier::isReserved($alias)) {
            throw new InvalidModel(sprintf('Import alias "%s" is not a usable PHP identifier.', $alias));
        }

        $this->namespace = ClassName::fromFqcn($namespace)->fqcn();
        $this->alias = $alias;
    }

    public function namespace(): string
    {
        return $this->namespace;
    }

    public function alias(): string
    {
        return $this->alias;
    }
}
