<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Infrastructure\Environment;

use Closure;
use MSSTC4PHP\DtoGenerator\Application\Port\ClassVerifier;
use MSSTC4PHP\DtoGenerator\Application\Port\ClassVerifierLocator;

final class AutoloadClassVerifierLocator implements ClassVerifierLocator
{
    private const SWITCH = 'DTO_GENERATOR_VERIFY_CLASSES';

    /** @var Closure(): ?string */
    private Closure $environment;

    /**
     * @param (Closure(): ?string)|null $environment the value of DTO_GENERATOR_VERIFY_CLASSES; the process's by default
     */
    public function __construct(?Closure $environment = null)
    {
        $this->environment = $environment ?? static function (): ?string {
            $value = getenv(self::SWITCH);

            return is_string($value) ? $value : null;
        };
    }

    /**
     * The autoloader of the nearest Composer project at or above the directory, in its configured vendor-dir.
     */
    public function locate(string $directory): ?ClassVerifier
    {
        $project = $directory;
        while (!is_file($project . '/composer.json')) {
            $parent = dirname($project);
            if ($parent === $project) {
                return null;
            }

            $project = $parent;
        }

        $autoload = $this->vendorDir($project) . '/autoload.php';

        return is_file($autoload) ? new AutoloadClassVerifier($autoload) : null;
    }

    /**
     * An unreadable or odd composer.json leaves Composer's default.
     */
    private function vendorDir(string $project): string
    {
        $json = json_decode((string) @file_get_contents($project . '/composer.json'), true);
        $config = is_array($json) ? $json['config'] ?? null : null;
        $vendorDir = is_array($config) ? $config['vendor-dir'] ?? null : null;
        if (!is_string($vendorDir) || $vendorDir === '') {
            return $project . '/vendor';
        }

        return $vendorDir[0] === '/' ? $vendorDir : $project . '/' . $vendorDir;
    }

    public function isDisabledByEnvironment(): bool
    {
        return ($this->environment)() === '0';
    }
}
