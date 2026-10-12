<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Port;

use InvalidArgumentException;
use MSSTC4PHP\DtoGenerator\Application\Config\RemoteHeader;

/**
 * How a remote document is loaded (spec F2 §2, F3): from which cache, whether and how it may be fetched, with which
 * headers.
 */
final class RemoteRequest
{
    /** The cache only: checks, dry runs and builds without the network. */
    public const CACHED = 'cached';

    /** Fetched when the cache lacks it: a run that writes. */
    public const MISSING = 'missing';

    /** Fetched again even when cached: `--refresh-remote`. */
    public const ALL = 'all';

    private string $cacheDir;

    /** @var positive-int */
    private int $timeout;

    /** @var self::CACHED|self::MISSING|self::ALL */
    private string $policy;

    /** @var list<RemoteHeader> */
    private array $headers;

    /**
     * @param string $cacheDir absolute directory of the cache
     * @param positive-int $timeout seconds the fetch may take
     * @param self::CACHED|self::MISSING|self::ALL $policy
     * @param list<RemoteHeader> $headers the headers whose prefix covers the document
     *
     * @throws InvalidArgumentException for an unknown policy
     */
    public function __construct(string $cacheDir, int $timeout, string $policy, array $headers = [])
    {
        if (!in_array($policy, [self::CACHED, self::MISSING, self::ALL], true)) {
            throw new InvalidArgumentException(sprintf('Unknown policy "%s" for remote documents.', $policy));
        }

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
        return $this->policy !== self::CACHED;
    }

    public function fetchesCached(): bool
    {
        return $this->policy === self::ALL;
    }

    /**
     * @return list<RemoteHeader>
     */
    public function headers(): array
    {
        return $this->headers;
    }
}
