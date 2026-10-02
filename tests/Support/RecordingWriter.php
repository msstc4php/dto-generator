<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Support;

use MSSTC4PHP\DtoGenerator\Application\Port\FileWriter;
use MSSTC4PHP\DtoGenerator\Application\Port\WriteFailed;
use MSSTC4PHP\DtoGenerator\Application\Service\Generate\GeneratedFile;
use MSSTC4PHP\DtoGenerator\Application\Service\Generate\WritePlan;

/**
 * Returns a fixed plan and records what Generate asked of it.
 */
final class RecordingWriter implements FileWriter
{
    private WritePlan $plan;

    private ?WriteFailed $failure;

    /** @var list<string>|null */
    public ?array $outputDirs = null;

    /** @var list<GeneratedFile>|null */
    public ?array $files = null;

    public bool $applied = false;

    public function __construct(WritePlan $plan, ?WriteFailed $failure = null)
    {
        $this->plan = $plan;
        $this->failure = $failure;
    }

    public function plan(array $outputDirs, array $files): WritePlan
    {
        $this->outputDirs = $outputDirs;
        $this->files = $files;

        return $this->plan;
    }

    public function apply(WritePlan $plan): void
    {
        if ($this->failure instanceof WriteFailed) {
            throw $this->failure;
        }

        $this->applied = true;
    }
}
