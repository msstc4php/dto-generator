<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Schema\ResolvedSchema;

/**
 * A generated class together with the named schema it is generated from.
 */
final class NamedClass
{
    private ResolvedSchema $schema;

    private ClassName $name;

    public function __construct(ResolvedSchema $schema, ClassName $name)
    {
        $this->schema = $schema;
        $this->name = $name;
    }

    public function schema(): ResolvedSchema
    {
        return $this->schema;
    }

    public function name(): ClassName
    {
        return $this->name;
    }
}
