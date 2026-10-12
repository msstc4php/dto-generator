<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Support;

use MSSTC4PHP\DtoGenerator\Application\Port\Document;
use MSSTC4PHP\DtoGenerator\Application\Port\DocumentLoadFailed;
use MSSTC4PHP\DtoGenerator\Application\Port\RemoteDocuments;
use MSSTC4PHP\DtoGenerator\Application\Port\RemoteRequest;

final class InMemoryRemoteDocuments implements RemoteDocuments
{
    /** @var array<string, array<array-key, mixed>|DocumentLoadFailed> */
    private array $documents;

    /** @var list<array{string, RemoteRequest}> */
    private array $loads = [];

    /**
     * @param array<string, array<array-key, mixed>|DocumentLoadFailed> $documents by URL; a failure is thrown on load
     */
    public function __construct(array $documents)
    {
        $this->documents = $documents;
    }

    public function load(string $url, RemoteRequest $request): Document
    {
        $this->loads[] = [$url, $request];
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
        return array_map(static fn (array $load): array => [$load[0], $load[1]->fetchesMissing()], $this->loads);
    }

    /**
     * @return list<array{string, RemoteRequest}>
     */
    public function requests(): array
    {
        return $this->loads;
    }
}
