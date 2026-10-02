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

    public function locate(string $directory): ?ClassVerifier
    {
        for ($current = $directory;; $current = $parent) {
            $autoload = $current . '/vendor/autoload.php';
            if (is_file($autoload)) {
                return new AutoloadClassVerifier($autoload);
            }

            $parent = dirname($current);
            if ($parent === $current) {
                return null;
            }
        }
    }

    public function isDisabledByEnvironment(): bool
    {
        return ($this->environment)() === '0';
    }
}
