<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Port;

use InvalidArgumentException;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Path;

final class Document
{
    private string $path;

    /** @var array<array-key, mixed> */
    private array $root;

    /**
     * @param string $path absolute and normalized; it is the document's identity in locations
     * @param array<array-key, mixed> $root the decoded top-level object
     */
    public function __construct(string $path, array $root)
    {
        if (!Path::isAbsolute($path) || Path::normalize($path) !== $path) {
            throw new InvalidArgumentException(sprintf('Document path "%s" must be absolute and normalized.', $path));
        }

        $this->path = $path;
        $this->root = $root;
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function root(): array
    {
        return $this->root;
    }
}
