<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Port;

use MSSTC4PHP\DtoGenerator\Application\Service\Generate\GeneratedFile;
use MSSTC4PHP\DtoGenerator\Application\Service\Generate\WritePlan;

/**
 * Writes the generated files of every output directory, keeping a manifest of what it owns (spec §9.1).
 */
interface FileWriter
{
    /**
     * @param list<string> $outputDirs every output directory of the config, including ones that no longer get files
     * @param list<GeneratedFile> $files the complete set; anything a manifest lists beyond it is stale
     */
    public function plan(array $outputDirs, array $files): WritePlan;

    /**
     * @throws WriteFailed
     */
    public function apply(WritePlan $plan): void;
}
