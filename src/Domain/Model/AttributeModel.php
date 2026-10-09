<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Model;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;

/**
 * An attribute as a config or a schema declares it (spec §7.1, §7.2), before its arguments are read.
 *
 * @phpstan-type AttributeDeclaration array{class: string, args?: JsonValue}
 *
 * @phpstan-import-type JsonValue from Json
 *
 * @api
 */
final class AttributeModel
{
    private ClassName $className;

    /** @var list<AttributeArgument> */
    private array $arguments;

    private ?ImportAlias $importAlias;

    /**
     * @param list<AttributeArgument> $arguments
     */
    public function __construct(ClassName $className, array $arguments = [], ?ImportAlias $importAlias = null)
    {
        AttributeArgument::assertWellFormed($arguments);

        if ($importAlias instanceof ImportAlias && !$this->isInside($className, $importAlias)) {
            throw new InvalidModel(sprintf('Attribute class %s is not inside the import alias namespace %s.', $className->fqcn(), $importAlias->namespace()));
        }

        $this->className = $className;
        $this->arguments = $arguments;
        $this->importAlias = $importAlias;
    }

    public function className(): ClassName
    {
        return $this->className;
    }

    /**
     * @return list<AttributeArgument>
     */
    public function arguments(): array
    {
        return $this->arguments;
    }

    public function importAlias(): ?ImportAlias
    {
        return $this->importAlias;
    }

    private function isInside(ClassName $className, ImportAlias $alias): bool
    {
        $prefix = $alias->namespace() . '\\';

        return strncasecmp($className->fqcn(), $prefix, strlen($prefix)) === 0;
    }
}
