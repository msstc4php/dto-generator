<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Infrastructure\Environment;

use JsonException;
use MSSTC4PHP\DtoGenerator\Application\Port\ProjectPackages;
use MSSTC4PHP\DtoGenerator\Application\Port\ProjectPackagesUnusable;
use MSSTC4PHP\DtoGenerator\Contract\InstalledPackages;

/**
 * Reads composer.lock beside the project's composer.json; it is data, so no code of the consumer runs.
 */
final class ComposerLockPackages implements ProjectPackages
{
    public function read(string $directory): InstalledPackages
    {
        $project = ComposerProject::nearest($directory);
        if ($project === null) {
            return new InstalledPackages();
        }

        $lock = $project . '/composer.lock';
        if (!is_file($lock)) {
            return new InstalledPackages();
        }

        $content = is_readable($lock) ? file_get_contents($lock) : false;
        if ($content === false) {
            throw ProjectPackagesUnusable::because($lock, 'cannot be read');
        }

        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw ProjectPackagesUnusable::because($lock, 'is not valid JSON');
        }

        // JSON lists decode to arrays too; an empty one passes like "{}", with no packages.
        if (!is_array($data) || ($data !== [] && array_values($data) === $data)) {
            throw ProjectPackagesUnusable::because($lock, 'does not contain an object');
        }

        $versions = [];
        foreach (['packages', 'packages-dev'] as $section) {
            $packages = $data[$section] ?? [];
            if (!is_array($packages)) {
                throw ProjectPackagesUnusable::because($lock, sprintf('has "%s" that is no list', $section));
            }

            foreach ($packages as $package) {
                $name = is_array($package) ? $package['name'] ?? null : null;
                $version = is_array($package) ? $package['version'] ?? null : null;
                if (!is_string($name) || !is_string($version) || $version === '') {
                    throw ProjectPackagesUnusable::because($lock, 'has a package without a name or version');
                }

                $versions[$name] = $version;
            }
        }

        return new InstalledPackages($versions);
    }
}
