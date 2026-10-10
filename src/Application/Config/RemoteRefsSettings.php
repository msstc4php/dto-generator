<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Config;

use InvalidArgumentException;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Path;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Url;

/**
 * The `remoteRefs` section (spec F2 §2): which URLs a $ref may name, where their documents are kept, how long one may
 * take to fetch.
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

    /**
     * @param list<string> $allow normalized URL prefixes
     * @param positive-int $timeout seconds per document
     *
     * @throws InvalidArgumentException for a relative cache directory or a timeout below one second
     */
    public function __construct(array $allow, string $cacheDir, int $timeout = self::TIMEOUT)
    {
        if (!Path::isAbsolute($cacheDir) || $timeout < 1) {
            throw new InvalidArgumentException(sprintf('Remote $refs need an absolute cache directory and a positive timeout, got "%s" and %d.', $cacheDir, $timeout));
        }

        $this->allow = $allow;
        $this->cacheDir = Path::normalize($cacheDir);
        $this->timeout = $timeout;
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
}
