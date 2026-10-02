<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Support;

use MSSTC4PHP\DtoGenerator\Application\Port\ProjectPackages;
use MSSTC4PHP\DtoGenerator\Application\Port\ProjectPackagesUnusable;
use MSSTC4PHP\DtoGenerator\Contract\InstalledPackages;

/**
 * A consumer with the given package versions, or with a composer.lock that cannot be used.
 */
final class FixedProjectPackages implements ProjectPackages
{
    /** @var array<string, string> */
    private array $versions;

    private ?string $failure;

    /**
     * @param array<string, string> $versions
     * @param string|null $failure why /project/composer.lock cannot be used
     */
    public function __construct(array $versions = [], ?string $failure = null)
    {
        $this->versions = $versions;
        $this->failure = $failure;
    }

    public function read(string $directory): InstalledPackages
    {
        if ($this->failure !== null) {
            throw ProjectPackagesUnusable::because('/project/composer.lock', $this->failure);
        }

        return new InstalledPackages($this->versions);
    }
}
