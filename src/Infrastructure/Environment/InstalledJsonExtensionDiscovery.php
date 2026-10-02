<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Infrastructure\Environment;

use Composer\InstalledVersions;
use JsonException;
use MSSTC4PHP\DtoGenerator\Application\Port\DiscoveredExtension;
use MSSTC4PHP\DtoGenerator\Application\Port\DiscoveredExtensions;
use MSSTC4PHP\DtoGenerator\Application\Port\ExtensionDiscovery;
use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use ReflectionClass;

/**
 * Reads vendor/composer/installed.json of the installation the generator runs from: the consumer's vendor when it is a
 * dependency, the image's in Docker. It is data, so no code of the consumer runs.
 */
final class InstalledJsonExtensionDiscovery implements ExtensionDiscovery
{
    private string $file;

    public function __construct(?string $file = null)
    {
        $this->file = $file ?? dirname((string) (new ReflectionClass(InstalledVersions::class))->getFileName()) . '/installed.json';
    }

    /**
     * The installed.json this discovery reads.
     */
    public function file(): string
    {
        return $this->file;
    }

    public function discover(): DiscoveredExtensions
    {
        if (!is_file($this->file)) {
            return new DiscoveredExtensions([]);
        }

        $content = is_readable($this->file) ? file_get_contents($this->file) : false;
        if ($content === false) {
            return $this->unusable('cannot be read');
        }

        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            return $this->unusable('is not valid JSON');
        }

        // Composer 2 writes {"packages": [...]}, Composer 1 the list itself; composer-runtime-api ^2 rules Composer 1
        // out for this package, but a hand-made or migrated file may still be a list.
        $packages = is_array($data) && array_key_exists('packages', $data) ? $data['packages'] : $data;
        if (!is_array($packages) || array_values($packages) !== $packages) {
            return $this->unusable('has no list of packages');
        }

        $extensions = [];
        $problems = [];
        foreach ($packages as $package) {
            $name = is_array($package) ? $package['name'] ?? null : null;
            if (!is_array($package) || !is_string($name) || $name === '') {
                $problems[] = sprintf('A package without a name in %s is skipped.', $this->file);

                continue;
            }

            $this->read($name, $package, $extensions, $problems);
        }

        return new DiscoveredExtensions($extensions, $problems);
    }

    /**
     * @param non-empty-string $name
     * @param array<array-key, mixed> $package one entry of installed.json, as decoded
     * @param list<DiscoveredExtension> $extensions
     * @param list<string> $problems
     */
    private function read(string $name, array $package, array &$extensions, array &$problems): void
    {
        $extra = is_array($package['extra'] ?? null) ? $package['extra'] : [];
        if (!array_key_exists('dto-generator', $extra)) {
            return;
        }

        $section = $extra['dto-generator'];
        if (!is_array($section) || ($section !== [] && array_values($section) === $section)) {
            $problems[] = sprintf('Package "%s" declares extra.dto-generator that is no object.', $name);

            return;
        }

        $classes = $section['extensions'] ?? [];
        if (!is_array($classes) || array_values($classes) !== $classes) {
            $problems[] = sprintf('Package "%s" declares extra.dto-generator.extensions that is no list of class names.', $name);

            return;
        }

        foreach ($classes as $class) {
            $className = is_string($class) ? $this->className($class) : null;
            if ($className instanceof ClassName) {
                $extensions[] = new DiscoveredExtension($name, $className);
            } else {
                $problems[] = sprintf('Package "%s" declares %s in extra.dto-generator.extensions, which is no class name.', $name, (string) json_encode($class));
            }
        }
    }

    private function unusable(string $reason): DiscoveredExtensions
    {
        return new DiscoveredExtensions([], [sprintf('%s %s; no extensions are discovered.', $this->file, $reason)]);
    }

    private function className(string $class): ?ClassName
    {
        try {
            return ClassName::fromFqcn($class);
        } catch (InvalidModel $exception) {
            return null;
        }
    }
}
