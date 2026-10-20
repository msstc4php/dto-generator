<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Service\Schemas\Load;

use MSSTC4PHP\DtoGenerator\Application\Port\Document;
use MSSTC4PHP\DtoGenerator\Domain\Builder\SchemaParser;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Schema\JsonPointer;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;

/**
 * Parses the schemas of one load: with the names the configuration gives, and as the version of their document says.
 *
 * @phpstan-import-type JsonValue from Json
 */
final class SchemaReader
{
    private SchemaParser $parser;

    private SchemaParser $openApi30;

    private NameOverrides $names;

    private Versions $versions;

    public function __construct(SchemaParser $parser, NameOverrides $names, Versions $versions)
    {
        $this->parser = $parser;
        $this->openApi30 = $parser->forOpenApi30();
        $this->names = $names;
        $this->versions = $versions;
    }

    /**
     * The same reader with another parser, sharing the names and versions.
     */
    public function withParser(SchemaParser $parser): self
    {
        return new self($parser, $this->names, $this->versions);
    }

    /**
     * @param JsonValue $node a component of the document a source reads
     */
    public function component(Document $document, $node, SchemaLocation $at, Diagnostics $diagnostics): Schema
    {
        $parser = $this->versions->ofSource($document) ? $this->openApi30 : $this->parser;

        return $parser->parse($this->names->apply($node, $at), $at, $diagnostics);
    }

    /**
     * The schema at the target, which the document holds.
     */
    public function referenced(Document $document, SchemaLocation $target, SchemaLocation $referredAt, Diagnostics $diagnostics): Schema
    {
        $parser = $this->versions->ofReferenced($document, $referredAt, $diagnostics) ? $this->openApi30 : $this->parser;

        return $parser->parse($this->names->apply(JsonPointer::get($document->root(), $target->pointer()), $target), $target, $diagnostics);
    }
}
