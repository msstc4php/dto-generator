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

        $normalized = ltrim($namespace, '\\');
        foreach (explode('\\', $normalized) as $segment) {
            // Reserved segments are legal from PHP 8.0; only the target-aware layer may reject them.
            if (!Identifier::isValid($segment)) {
                throw new InvalidModel(sprintf('"%s" is not a valid namespace: segment "%s" is not a PHP identifier.', $namespace, $segment));
            }
        }

        $this->namespace = $normalized;
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
