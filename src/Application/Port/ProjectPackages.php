<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Port;

use MSSTC4PHP\DtoGenerator\Contract\InstalledPackages;

interface ProjectPackages
{
    /**
     * The package versions locked by the nearest Composer project at or above the directory (spec §8); none when there
     * is no project or no lock.
     *
     * @throws ProjectPackagesUnusable
     */
    public function read(string $directory): InstalledPackages;
}
