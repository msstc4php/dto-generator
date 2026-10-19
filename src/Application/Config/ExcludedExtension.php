<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Config;

use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Model\Identifier;

/**
 * An entry of discoverExtensions.exclude: a package none of whose declared extensions discovery loads, or one extension.
 */
final class ExcludedExtension
{
    /** @var non-negative-int */
    private int $index;

    /** the package name or the class, as written */
    private string $name;

    /** what a discovered package or class is compared with: class names are case-insensitive in PHP */
    private string $key;

    private bool $isClass;

    /**
     * @param non-negative-int $index
     */
    private function __construct(int $index, string $name, bool $isClass)
    {
        $this->index = $index;
        $this->name = $name;
        $this->key = $isClass ? Identifier::asciiLower($name) : $name;
        $this->isClass = $isClass;
    }

    /**
     * @param non-negative-int $index the position of the entry in the list
     * @param non-empty-string $name
     */
    public static function package(int $index, string $name): self
    {
        return new self($index, $name, false);
    }

    /**
     * @param non-negative-int $index the position of the entry in the list
     */
    public static function extension(int $index, ClassName $class): self
    {
        return new self($index, $class->fqcn(), true);
    }

    /**
     * @return non-negative-int
     */
    public function index(): int
    {
        return $this->index;
    }

    public function excludes(string $package, ClassName $class): bool
    {
        return $this->key === ($this->isClass ? Identifier::asciiLower($class->fqcn()) : $package);
    }

    public function describe(): string
    {
        return $this->name;
    }
}
