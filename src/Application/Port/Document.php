<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Port;

use InvalidArgumentException;
use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Path;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Url;

final class Document
{
    private string $path;

    /** @var array<array-key, mixed> */
    private array $root;

    /**
     * @param string $path absolute and normalized, or a normalized URL; it is the document's identity in locations
     * @param array<array-key, mixed> $root the decoded top-level object
     */
    public function __construct(string $path, array $root)
    {
        $normalized = Url::isUrl($path) ? $this->normalizedUrl($path) : (Path::isAbsolute($path) ? Path::normalize($path) : null);
        if ($normalized !== $path) {
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

    private function normalizedUrl(string $url): ?string
    {
        try {
            return Url::normalize($url);
        } catch (InvalidModel $exception) {
            return null;
        }
    }
}
