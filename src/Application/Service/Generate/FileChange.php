<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Service\Generate;

/**
 * What writing does to one file.
 *
 * @api
 */
final class FileChange
{
    public const CREATE = 'create';

    public const UPDATE = 'update';

    public const DELETE = 'delete';

    public const UNCHANGED = 'unchanged';

    /** @var self::CREATE|self::UPDATE|self::DELETE|self::UNCHANGED */
    private string $kind;

    private string $path;

    private ?string $contents;

    /**
     * @param self::CREATE|self::UPDATE|self::DELETE|self::UNCHANGED $kind
     */
    private function __construct(string $kind, string $path, ?string $contents)
    {
        $this->kind = $kind;
        $this->path = $path;
        $this->contents = $contents;
    }

    public static function create(string $path, string $contents): self
    {
        return new self(self::CREATE, $path, $contents);
    }

    public static function update(string $path, string $contents): self
    {
        return new self(self::UPDATE, $path, $contents);
    }

    public static function delete(string $path): self
    {
        return new self(self::DELETE, $path, null);
    }

    public static function unchanged(string $path): self
    {
        return new self(self::UNCHANGED, $path, null);
    }

    /**
     * @return self::CREATE|self::UPDATE|self::DELETE|self::UNCHANGED
     */
    public function kind(): string
    {
        return $this->kind;
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * The new contents of a created or updated file.
     */
    public function contents(): ?string
    {
        return $this->contents;
    }

    public function isChange(): bool
    {
        return $this->kind !== self::UNCHANGED;
    }
}
