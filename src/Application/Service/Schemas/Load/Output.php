<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Service\Schemas\Load;

use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaGraph;

final class Output
{
    private SchemaGraph $graph;

    private Diagnostics $diagnostics;

    /** @var list<string> */
    private array $documents;

    private ?string $remoteCache;

    /**
     * @param list<string> $documents the local documents read
     * @param string|null $remoteCache the cache directory, when remote documents were read
     */
    public function __construct(SchemaGraph $graph, Diagnostics $diagnostics, array $documents = [], ?string $remoteCache = null)
    {
        $this->graph = $graph;
        $this->diagnostics = $diagnostics;
        $this->documents = $documents;
        $this->remoteCache = $remoteCache;
    }

    /**
     * @return list<string>
     */
    public function documents(): array
    {
        return $this->documents;
    }

    public function remoteCache(): ?string
    {
        return $this->remoteCache;
    }

    public function graph(): SchemaGraph
    {
        return $this->graph;
    }

    public function diagnostics(): Diagnostics
    {
        return $this->diagnostics;
    }
}
