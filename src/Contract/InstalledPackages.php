<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Contract;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;

/**
 * Versions of the packages the consuming project has installed, so an extension can target, say, symfony/validator 6 or 7.
 *
 * @api
 */
final class InstalledPackages
{
    /** @var array<string, non-empty-string> */
    private array $versions;

    /**
     * @param array<string, string> $versions package name → version
     */
    public function __construct(array $versions = [])
    {
        $checked = [];
        foreach ($versions as $package => $version) {
            if ($version === '') {
                throw new InvalidModel(sprintf('Package "%s" has no version.', $package));
            }

            $checked[$package] = $version;
        }

        $this->versions = $checked;
    }

    public function has(string $package): bool
    {
        return isset($this->versions[$package]);
    }

    /**
     * @return non-empty-string|null
     */
    public function version(string $package): ?string
    {
        return $this->versions[$package] ?? null;
    }
}
