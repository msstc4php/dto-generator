<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Infrastructure\Environment;

/**
 * The consuming project: the directory of the nearest composer.json at or above a directory. The COMPOSER variable,
 * which names another file, is not honoured.
 */
final class ComposerProject
{
    private function __construct()
    {
    }

    public static function nearest(string $directory): ?string
    {
        $project = $directory;
        while (!is_file($project . '/composer.json')) {
            $parent = dirname($project);
            if ($parent === $project) {
                return null;
            }

            $project = $parent;
        }

        return $project;
    }
}
