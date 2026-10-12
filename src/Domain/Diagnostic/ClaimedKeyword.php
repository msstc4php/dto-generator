<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Diagnostic;

use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;

/**
 * One occurrence of a keyword an extension claims: the keyword and the schema that holds it.
 *
 * @api
 */
final class ClaimedKeyword
{
    private SchemaLocation $schema;

    private string $keyword;

    public function __construct(SchemaLocation $schema, string $keyword)
    {
        $this->schema = $schema;
        $this->keyword = $keyword;
    }

    public function schema(): SchemaLocation
    {
        return $this->schema;
    }

    public function keyword(): string
    {
        return $this->keyword;
    }

    public function location(): SchemaLocation
    {
        return $this->schema->child($this->keyword);
    }
}
