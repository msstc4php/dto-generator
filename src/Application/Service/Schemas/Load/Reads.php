<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Service\Schemas\Load;

/**
 * What one run read: the local documents, and whether any remote one came from the cache.
 */
final class Reads
{
    /** @var array<string, true> */
    private array $documents = [];

    private bool $remote = false;

    public function document(string $path): void
    {
        $this->documents[$path] = true;
    }

    public function remote(): void
    {
        $this->remote = true;
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
        return $this->remote;
    }
}
