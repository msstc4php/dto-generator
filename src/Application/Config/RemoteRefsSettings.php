<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Config;

use InvalidArgumentException;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Path;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Url;

/**
 * The `remoteRefs` section (spec F2 §2, F3 §2): which URLs a $ref may name, where their documents are kept, how long one
 * may take to fetch, which headers go with the fetch.
 */
final class RemoteRefsSettings
{
    public const CACHE_DIR = '.dto-generator/remote';

    public const TIMEOUT = 10;

    /** @var list<string> */
    private array $allow;

    private string $cacheDir;

    /** @var positive-int */
    private int $timeout;

    /** @var list<RemoteHeader> */
    private array $headers;

    /**
     * @param list<string> $allow normalized URL prefixes
     * @param positive-int $timeout seconds per document
     * @param list<RemoteHeader> $headers
     *
     * @throws InvalidArgumentException for a relative cache directory or a timeout below one second
     */
    public function __construct(array $allow, string $cacheDir, int $timeout = self::TIMEOUT, array $headers = [])
    {
        if (!Path::isAbsolute($cacheDir) || $timeout < 1) {
            throw new InvalidArgumentException(sprintf('Remote $refs need an absolute cache directory and a positive timeout, got "%s" and %d.', $cacheDir, $timeout));
        }

        $this->allow = $allow;
        $this->cacheDir = Path::normalize($cacheDir);
        $this->timeout = $timeout;
        $this->headers = $headers;
    }

    /**
     * Whether a normalized URL lies under one of the allowed prefixes.
     */
    public function allows(string $url): bool
    {
        foreach ($this->allow as $prefix) {
            if (Url::isUnder($url, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function allow(): array
    {
        return $this->allow;
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

    /**
     * @return list<RemoteHeader>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * The headers whose prefix covers a normalized URL; of those with one name, the one with the longest prefix.
     *
     * @return list<RemoteHeader>
     */
    public function headersFor(string $url): array
    {
        $chosen = [];
        foreach ($this->headers as $header) {
            $name = strtolower($header->name());
            if (Url::isUnder($url, $header->prefix()) && (!isset($chosen[$name]) || strlen($header->prefix()) > strlen($chosen[$name]->prefix()))) {
                $chosen[$name] = $header;
            }
        }

        return array_values($chosen);
    }
}
