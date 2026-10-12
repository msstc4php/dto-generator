<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Service\Schemas\Load;

use MSSTC4PHP\DtoGenerator\Application\Port\Document;

/**
 * What one run read: the local documents, and the remote ones, kept so each is loaded, and refreshed, once per run.
 */
final class Reads
{
    /** @var array<string, true> */
    private array $documents = [];

    /** @var array<string, Document> by URL */
    private array $remote = [];

    public function document(string $path): void
    {
        $this->documents[$path] = true;
    }

    public function remote(Document $document): void
    {
        $this->remote[$document->path()] = $document;
    }

    public function remoteDocument(string $url): ?Document
    {
        return $this->remote[$url] ?? null;
    }

    /**
     * @return list<string>
     */
    public function documents(): array
    {
        return array_keys($this->documents);
    }

    public function readsRemote(): bool
    {
        return $this->remote !== [];
    }
}
