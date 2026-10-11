<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Service\Generate;

use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;

/**
 * @api
 */
final class Output
{
    private Status $status;

    private Diagnostics $diagnostics;

    private ?WritePlan $plan;

    /** @var list<GeneratedFile> */
    private array $files;

    /** @var list<string> */
    private array $documents;

    private ?string $remoteCache;

    /**
     * @param list<GeneratedFile> $files
     * @param list<string> $documents the config and the local documents the run read
     * @param string|null $remoteCache the cache directory, when the run read remote documents
     */
    public function __construct(Status $status, Diagnostics $diagnostics, ?WritePlan $plan, array $files, array $documents = [], ?string $remoteCache = null)
    {
        $this->status = $status;
        $this->diagnostics = $diagnostics;
        $this->plan = $plan;
        $this->files = $files;
        $this->documents = $documents;
        $this->remoteCache = $remoteCache;
    }

    public function status(): Status
    {
        return $this->status;
    }

    public function diagnostics(): Diagnostics
    {
        return $this->diagnostics;
    }

    /**
     * Null when the run stopped before planning (a config or schema error).
     */
    public function plan(): ?WritePlan
    {
        return $this->plan;
    }

    /**
     * @return list<GeneratedFile>
     */
    public function files(): array
    {
        return $this->files;
    }

    /**
     * The generated classes and their files, for an autoloader. Also after --check or --dry-run: they describe what the
     * run would write.
     *
     * @return array<string, string> fully qualified class name → file
     */
    public function classes(): array
    {
        $classes = [];
        foreach ($this->files as $file) {
            $class = $file->className();
            if ($class !== null) {
                $classes[$class] = $file->path();
            }
        }

        return $classes;
    }

    /**
     * The config and the local documents the run read, so a cache of its result knows when to run again. Not listed:
     * Composer's files (composer.json, composer.lock, vendor/composer/installed.json) and the classes the run checked
     * or loaded as extensions.
     *
     * @return list<string>
     */
    public function documents(): array
    {
        return $this->documents;
    }

    /**
     * remoteRefs.cacheDir when the run read remote documents, which come from there; null otherwise.
     */
    public function remoteCache(): ?string
    {
        return $this->remoteCache;
    }
}
