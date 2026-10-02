<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Schema\ResolvedSchema;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;

/**
 * A generated class together with the named schema it is generated from.
 */
final class NamedClass
{
    private ResolvedSchema $schema;

    private ClassName $name;

    /** @var list<Schema> */
    private array $via;

    /**
     * @param list<Schema> $via the schemas with a `$ref` a reference passed before it reached the class: aliases, and the
     *                          `$ref` members of `allOf` wrappers
     */
    public function __construct(ResolvedSchema $schema, ClassName $name, array $via = [])
    {
        $this->schema = $schema;
        $this->name = $name;
        $this->via = $via;
    }

    /**
     * @return list<Schema>
     */
    public function via(): array
    {
        return $this->via;
    }

    public function schema(): ResolvedSchema
    {
        return $this->schema;
    }

    public function name(): ClassName
    {
        return $this->name;
    }

    public function isGeneratedFrom(Schema $schema): bool
    {
        return $this->schema->location()->equals($schema->location());
    }
}
