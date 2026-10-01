<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Config;

use InvalidArgumentException;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Path;

final class GeneratorConfig
{
    private string $path;

    private TargetSettings $target;

    private DtoSettings $dto;

    /** @var array<string, ClassName> */
    private array $formats;

    private ExtensionSettings $extensions;

    /** @var non-empty-list<SourceConfig> */
    private array $sources;

    /**
     * @param string $path absolute path of the config file; relative paths inside it were resolved against its directory
     * @param array<string, ClassName> $formats custom format → PHP type
     * @param non-empty-list<SourceConfig> $sources
     */
    public function __construct(string $path, TargetSettings $target, DtoSettings $dto, array $formats, ExtensionSettings $extensions, array $sources)
    {
        if (!Path::isAbsolute($path)) {
            throw new InvalidArgumentException(sprintf('Config path "%s" must be absolute.', $path));
        }

        $this->path = Path::normalize($path);
        $this->target = $target;
        $this->dto = $dto;
        $this->formats = $formats;
        $this->extensions = $extensions;
        $this->sources = $sources;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function baseDir(): string
    {
        return Path::directory($this->path);
    }

    /**
     * Root of every config diagnostic.
     */
    public function location(): SchemaLocation
    {
        return new SchemaLocation($this->path);
    }

    public function target(): TargetSettings
    {
        return $this->target;
    }

    public function dto(): DtoSettings
    {
        return $this->dto;
    }

    /**
     * @return array<string, ClassName>
     */
    public function formats(): array
    {
        return $this->formats;
    }

    public function extensions(): ExtensionSettings
    {
        return $this->extensions;
    }

    /**
     * @return non-empty-list<SourceConfig>
     */
    public function sources(): array
    {
        return $this->sources;
    }
}
