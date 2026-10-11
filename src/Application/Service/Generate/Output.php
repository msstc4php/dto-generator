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

    /**
     * @param list<GeneratedFile> $files
     * @param list<string> $documents the files the run read: the config, the local documents, the remote cache's index
     */
    public function __construct(Status $status, Diagnostics $diagnostics, ?WritePlan $plan, array $files, array $documents = [])
    {
        $this->status = $status;
        $this->diagnostics = $diagnostics;
        $this->plan = $plan;
        $this->files = $files;
        $this->documents = $documents;
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
     * The generated classes and their files, for an autoloader.
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
     * Whatever the run read, so a cache of its result knows when to run again.
     *
     * @return list<string>
     */
    public function documents(): array
    {
        return $this->documents;
    }
}
