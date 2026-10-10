<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Support;

use MSSTC4PHP\DtoGenerator\Application\Config\RemoteRefsSettings;
use MSSTC4PHP\DtoGenerator\Application\Port\Document;
use MSSTC4PHP\DtoGenerator\Application\Port\DocumentLoadFailed;
use MSSTC4PHP\DtoGenerator\Application\Port\RemoteDocuments;

final class InMemoryRemoteDocuments implements RemoteDocuments
{
    /** @var array<string, array<array-key, mixed>|DocumentLoadFailed> */
    private array $documents;

    /** @var list<array{string, bool}> */
    private array $loads = [];

    /**
     * @param array<string, array<array-key, mixed>|DocumentLoadFailed> $documents by URL; a failure is thrown on load
     */
    public function __construct(array $documents)
    {
        $this->documents = $documents;
    }

    public function load(string $url, RemoteRefsSettings $settings, bool $fetch): Document
    {
        $this->loads[] = [$url, $fetch];
        $document = $this->documents[$url] ?? DocumentLoadFailed::remote($url, 'is not here.');
        if ($document instanceof DocumentLoadFailed) {
            throw $document;
        }

        return new Document($url, $document);
    }

    /**
     * @return list<array{string, bool}> URL and whether it could be fetched, per load
     */
    public function loads(): array
    {
        return $this->loads;
    }
}
