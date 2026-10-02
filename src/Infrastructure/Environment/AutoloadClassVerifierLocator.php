<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Infrastructure\Environment;

use Closure;
use MSSTC4PHP\DtoGenerator\Application\Port\ClassVerifier;
use MSSTC4PHP\DtoGenerator\Application\Port\ClassVerifierLocator;

final class AutoloadClassVerifierLocator implements ClassVerifierLocator
{
    private const SWITCH = 'DTO_GENERATOR_VERIFY_CLASSES';

    /** @var Closure(string): ?string */
    private Closure $environment;

    /**
     * @param (Closure(string): ?string)|null $environment reads an environment variable; the process's by default
     */
    public function __construct(?Closure $environment = null)
    {
        $this->environment = $environment ?? static function (string $name): ?string {
            $value = getenv($name);

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

    public function isDisabledByEnvironment(): bool
    {
        return ($this->environment)(self::SWITCH) === '0';
    }

    /**
     * Like Composer: COMPOSER_VENDOR_DIR first, then config.vendor-dir; an unreadable or odd composer.json leaves
     * "vendor". The COMPOSER variable (another composer.json) and "~" are not honoured.
     */
    private function vendorDir(string $project): string
    {
        $vendorDir = ($this->environment)('COMPOSER_VENDOR_DIR');
        if ($vendorDir === null || $vendorDir === '') {
            $json = json_decode((string) @file_get_contents($project . '/composer.json'), true);
            $config = is_array($json) ? $json['config'] ?? null : null;
            $vendorDir = is_array($config) ? $config['vendor-dir'] ?? null : null;
        }

        if (!is_string($vendorDir) || $vendorDir === '') {
            return $project . '/vendor';
        }

        return preg_match('~^([a-zA-Z]:)?[/\\\\]~', $vendorDir) === 1 ? $vendorDir : $project . '/' . $vendorDir;
    }
}
