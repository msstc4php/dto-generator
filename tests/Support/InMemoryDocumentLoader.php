<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Support;

use MSSTC4PHP\DtoGenerator\Application\Port\Document;
use MSSTC4PHP\DtoGenerator\Application\Port\DocumentLoader;
use MSSTC4PHP\DtoGenerator\Application\Port\DocumentLoadFailed;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Path;

final class InMemoryDocumentLoader implements DocumentLoader
{
    /** @var array<string, array<array-key, mixed>|DocumentLoadFailed> */
    private array $documents;

    /**
     * @param array<string, array<array-key, mixed>|DocumentLoadFailed> $documents keyed by absolute path; a failure is thrown on load
     */
    public function __construct(array $documents)
    {
        $this->documents = $documents;
    }

    public function load(string $path): Document
    {
        $normalized = Path::normalize($path);
        if (!isset($this->documents[$normalized])) {
            throw DocumentLoadFailed::notFound($normalized);
        }

        $document = $this->documents[$normalized];
        if ($document instanceof DocumentLoadFailed) {
            throw $document;
        }

        return new Document($normalized, $document);
    }
}
