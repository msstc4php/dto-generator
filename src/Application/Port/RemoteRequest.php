<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Port;

use MSSTC4PHP\DtoGenerator\Application\Config\RemoteHeader;

/**
 * How a remote document is loaded (spec F2 §2, F3): from which cache, whether and how it may be fetched, with which
 * headers.
 */
final class RemoteRequest
{
    private string $cacheDir;

    /** @var positive-int */
    private int $timeout;

    private RemotePolicy $policy;

    /** @var list<RemoteHeader> */
    private array $headers;

    /**
     * @param string $cacheDir absolute directory of the cache
     * @param positive-int $timeout seconds the fetch may take
     * @param list<RemoteHeader> $headers the headers whose prefix covers the document
     */
    public function __construct(string $cacheDir, int $timeout, RemotePolicy $policy, array $headers = [])
    {
        $this->cacheDir = $cacheDir;
        $this->timeout = $timeout;
        $this->policy = $policy;
        $this->headers = $headers;
    }

    public function cacheDir(): string
    {
        return $this->cacheDir;
    }

    /**
     * @return positive-int
     */
    public function timeout(): int
    {
        return $this->timeout;
    }

    public function fetchesMissing(): bool
    {
        return $this->policy->value() !== RemotePolicy::CACHED;
    }

    public function fetchesCached(): bool
    {
        return $this->policy->value() === RemotePolicy::ALL;
    }

    /**
     * @return list<RemoteHeader>
     */
    public function headers(): array
    {
        return $this->headers;
    }
}
