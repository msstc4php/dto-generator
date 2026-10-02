<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Presentation\Composer;

use LogicException;

/**
 * `extra.dto-generator` of the root package (spec §9.3): the config to generate from, or what is wrong with it.
 */
final class PluginSettings
{
    /** @var non-empty-string|null */
    private ?string $config;

    private bool $failOnError;

    /** @var non-empty-string|null */
    private ?string $problem;

    /**
     * @param non-empty-string|null $config
     * @param non-empty-string|null $problem
     */
    private function __construct(?string $config, bool $failOnError, ?string $problem)
    {
        $this->config = $config;
        $this->failOnError = $failOnError;
        $this->problem = $problem;
    }

    /**
     * @param array<array-key, mixed> $extra the root package's extra, as Composer decodes it
     *
     * @return self|null null when the package does not set extra.dto-generator
     */
    public static function fromExtra(array $extra): ?self
    {
        if (!array_key_exists('dto-generator', $extra)) {
            return null;
        }

        $settings = $extra['dto-generator'];
        if (!is_array($settings) || ($settings !== [] && array_values($settings) === $settings)) {
            return new self(null, false, 'extra.dto-generator must be an object with "config", the path of the config file.');
        }

        $failOnError = $settings['failOnError'] ?? false;
        if (!is_bool($failOnError)) {
            return new self(null, false, 'extra.dto-generator.failOnError must be true or false.');
        }

        if (!array_key_exists('config', $settings)) {
            return new self(null, $failOnError, 'extra.dto-generator has no "config", so nothing is generated.');
        }

        $config = $settings['config'];
        if (!is_string($config) || $config === '') {
            return new self(null, $failOnError, 'extra.dto-generator.config must be the path of the config file.');
        }

        return new self($config, $failOnError, null);
    }

    /**
     * @return non-empty-string
     *
     * @throws LogicException when the settings have a problem instead
     */
    public function config(): string
    {
        if ($this->config === null) {
            throw new LogicException('Settings with a problem have no config.');
        }

        return $this->config;
    }

    public function failOnError(): bool
    {
        return $this->failOnError;
    }

    /**
     * @return non-empty-string|null
     */
    public function problem(): ?string
    {
        return $this->problem;
    }
}
