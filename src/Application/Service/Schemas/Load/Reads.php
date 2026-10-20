<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Service\Schemas\Load;

use MSSTC4PHP\DtoGenerator\Application\Port\Document;

/**
 * What one run read: the local documents, and the remote ones, kept so each is loaded, and refreshed, once per run,
 * and the anchors of each document a `$ref` looked one up in.
 */
final class Reads
{
    /** @var array<string, true> */
    private array $documents = [];

    /** @var array<string, Document> by URL */
    private array $remote = [];

    /** @var array<string, Anchors> by document path */
    private array $anchors = [];

    public function document(string $path): void
    {
        $this->documents[$path] = true;
    }

    public function remote(Document $document): void
    {
        $this->remote[$document->path()] = $document;
    }

    public function anchors(Document $document): Anchors
    {
        return $this->anchors[$document->path()] ??= new Anchors($document->root());
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
