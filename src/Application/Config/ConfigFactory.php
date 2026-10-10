<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Config;

use InvalidArgumentException;
use MSSTC4PHP\DtoGenerator\Domain\Builder\ExtensionVocabulary;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;
use MSSTC4PHP\DtoGenerator\Domain\Exception\UnsupportedPhpVersion;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Model\Identifier;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Extensions;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use MSSTC4PHP\DtoGenerator\Domain\Shared\AbstractEnum;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Path;
use MSSTC4PHP\DtoGenerator\Domain\Target\AccessorStyle;
use MSSTC4PHP\DtoGenerator\Domain\Target\AllOfStrategy;
use MSSTC4PHP\DtoGenerator\Domain\Target\DateTimeClass;
use MSSTC4PHP\DtoGenerator\Domain\Target\MetadataMode;
use MSSTC4PHP\DtoGenerator\Domain\Target\Mutability;
use MSSTC4PHP\DtoGenerator\Domain\Target\PhpVersion;
use MSSTC4PHP\DtoGenerator\Domain\Target\ReadWriteModels;

/**
 * Validates a decoded `dto-generator.yaml` (spec §4) into a {@see GeneratorConfig}.
 */
final class ConfigFactory
{
    private const ROOT_KEYS = [
        'version', 'target', 'dto', 'formats', 'attributeAliases', 'verifyClasses', 'discoverExtensions',
        'extensions', 'extensionConfig', 'sources',
    ];

    /**
     * @param array<array-key, mixed> $raw
     * @param string $path absolute path of the config file
     */
    public function create(array $raw, string $path, Diagnostics $diagnostics): ?GeneratorConfig
    {
        if (!Path::isAbsolute($path)) {
            throw new InvalidArgumentException(sprintf('Config path "%s" must be absolute.', $path));
        }

        $errors = count($diagnostics->errors());
        $root = new RawSection($raw, new SchemaLocation(Path::normalize($path)), $diagnostics);
        $root->rejectUnknownKeys(self::ROOT_KEYS);
        if (!$root->has('version')) {
            $root->report('"version" is required.');
        } elseif ($root->raw('version') !== 1) {
            $root->error('version', 'must be 1');
        }

        $target = $this->target($root->section('target'));
        $dto = $this->dto($root->section('dto'));
        $formats = $this->formats($root->section('formats'));
        $extensions = $this->extensions($root);
        $sources = $this->sources($root, Path::directory($path));

        if (count($diagnostics->errors()) > $errors || $sources === []) {
            return null;
        }

        return new GeneratorConfig($path, $target, $dto, $formats, $extensions, $sources);
    }

    private function target(RawSection $section): TargetSettings
    {
        $section->rejectUnknownKeys(['php', 'metadata', 'strict']);

        $php = null;
        $value = $section->raw('php') ?? 'auto';
        // Unquoted `php: 8.2` arrives from YAML as a float.
        if (is_float($value)) {
            $value = Json::floatToString($value);
        } elseif (is_int($value)) {
            $value = (string) $value;
        }

        if (!is_string($value)) {
            $section->error('php', 'must be "auto" or a version such as "8.2"');
        } elseif ($value !== 'auto') {
            try {
                $php = PhpVersion::fromString($value);
            } catch (UnsupportedPhpVersion $exception) {
                $section->report($exception->getMessage(), 'php');
            }
        }

        $metadata = $section->choice('metadata', 'auto', array_merge(['auto'], $this->values(MetadataMode::cases())));

        return new TargetSettings(
            $php,
            $metadata === 'auto' ? null : MetadataMode::from($metadata),
            $section->bool('strict', true),
        );
    }

    private function dto(RawSection $section): DtoSettings
    {
        $section->rejectUnknownKeys(['mutability', 'accessors', 'dateTimeClass', 'allOfStrategy', 'withers', 'readWriteModels', 'readWriteSuffixes']);

        $mutability = Mutability::from($section->choice('mutability', Mutability::IMMUTABLE, $this->values(Mutability::cases())));
        $withers = $section->bool('withers', true);
        $readWriteModels = ReadWriteModels::from($section->choice('readWriteModels', ReadWriteModels::SINGLE, $this->values(ReadWriteModels::cases())));
        if ($section->has('readWriteSuffixes') && !$readWriteModels->isSplit()) {
            $section->warn('"readWriteSuffixes" has no effect without "readWriteModels: split".', 'readWriteSuffixes');
        }

        if (!$withers && !$mutability->isImmutable()) {
            $section->warn('"withers: false" has no effect on mutable DTOs, which keep their setters; it applies to schemas made immutable with x-dto-mutable: false.', 'withers');
        }

        return new DtoSettings(
            $mutability,
            AccessorStyle::from($section->choice('accessors', AccessorStyle::AUTO, $this->values(AccessorStyle::cases()))),
            DateTimeClass::from($section->choice('dateTimeClass', DateTimeClass::IMMUTABLE, $this->values(DateTimeClass::cases()))),
            AllOfStrategy::from($section->choice('allOfStrategy', AllOfStrategy::EXTENDS, $this->values(AllOfStrategy::cases()))),
            $withers,
            $readWriteModels,
            $this->viewSuffixes($section->section('readWriteSuffixes')),
        );
    }

    private function viewSuffixes(RawSection $section): ViewSuffixes
    {
        $section->rejectUnknownKeys(['read', 'write']);
        $suffixes = [];
        foreach (['read' => ViewSuffixes::READ, 'write' => ViewSuffixes::WRITE] as $key => $default) {
            $value = $section->has($key) ? $section->raw($key) : $default;
            if (!is_string($value) || !Identifier::isValid($value)) {
                $section->error($key, 'must be a PHP identifier');
                $value = $default;
            }

            $suffixes[$key] = $value;
        }

        // File names compare letter case ignored on many systems, and PHP class names always.
        if (Identifier::asciiLower($suffixes['read']) === Identifier::asciiLower($suffixes['write'])) {
            $section->report('The read and write suffixes must differ.');

            return new ViewSuffixes();
        }

        return new ViewSuffixes($suffixes['read'], $suffixes['write']);
    }

    /**
     * @return array<int|string, ClassName>
     */
    private function formats(RawSection $section): array
    {
        $formats = [];
        foreach ($section->keys() as $name) {
            $entry = $section->section($name);
            $entry->rejectUnknownKeys(['type']);
            $type = $entry->requiredString('type');
            if ($type === null) {
                continue;
            }

            try {
                $formats[$name] = ClassName::fromFqcn($type);
            } catch (InvalidModel $exception) {
                $entry->report($exception->getMessage(), 'type');
            }
        }

        return $formats;
    }

    private function extensions(RawSection $root): ExtensionSettings
    {
        $classes = [];
        foreach ($root->stringList('extensions', []) as $index => $class) {
            try {
                $classes[] = ClassName::fromFqcn($class);
            } catch (InvalidModel $exception) {
                $root->report($exception->getMessage(), 'extensions', (string) $index);
            }
        }

        $aliasSection = $root->section('attributeAliases');
        $aliases = [];
        foreach ($aliasSection->keys() as $name) {
            $core = strncmp($name, 'x-php-', 6) === 0 || strncmp($name, 'x-dto-', 6) === 0 || in_array($name, ExtensionVocabulary::KNOWN, true);
            if (!Extensions::isExtensionKey($name) || $core) {
                $aliasSection->report(sprintf('Alias "%s" must be an "x-" key outside the reserved "x-php-" and "x-dto-" prefixes.', $name), $name);

                continue;
            }

            $value = $aliasSection->raw($name);
            if (!is_array($value) || Json::isList($value) || !is_string($value['class'] ?? null) || array_diff(array_keys($value), ['class', 'args']) !== []) {
                $aliasSection->report('An alias must be an object with "class" and optional "args".', $name);

                continue;
            }

            $aliases[$name] = array_key_exists('args', $value) ? ['class' => $value['class'], 'args' => Json::value($value['args'])] : ['class' => $value['class']];
        }

        $verify = $root->raw('verifyClasses') ?? 'auto';
        if ($verify !== 'auto' && !is_bool($verify)) {
            $root->error('verifyClasses', 'must be "auto", true or false');
            $verify = 'auto';
        }

        $configSection = $root->section('extensionConfig');
        $config = [];
        foreach ($configSection->keys() as $name) {
            $config[$name] = $configSection->raw($name);
        }

        return new ExtensionSettings($classes, $root->bool('discoverExtensions', true), $config, $aliases, is_bool($verify) ? $verify : null);
    }

    /**
     * @return list<SourceConfig>
     */
    private function sources(RawSection $root, string $baseDir): array
    {
        $sources = [];
        foreach ($root->sectionList('sources') as $section) {
            $section->rejectUnknownKeys(['spec', 'namespace', 'outputDir', 'include', 'exclude']);
            $spec = $section->requiredString('spec');
            $namespace = $section->requiredString('namespace');
            $outputDir = $section->requiredString('outputDir');
            $include = $section->stringList('include', ['*']);
            $exclude = $section->stringList('exclude', []);

            if ($namespace !== null) {
                try {
                    $namespace = Identifier::normalizeQualifiedName($namespace, 'namespace');
                } catch (InvalidModel $exception) {
                    $section->report($exception->getMessage(), 'namespace');
                    $namespace = null;
                }
            }

            if ($section->raw('include') === []) {
                $section->error('include', 'must not be empty');
            }

            if ($spec === null || $namespace === null || $outputDir === null || $include === []) {
                continue;
            }

            $sources[] = new SourceConfig(Path::resolve($baseDir, $spec), $namespace, Path::resolve($baseDir, $outputDir), $include, $exclude);
        }

        return $sources;
    }

    /**
     * @param list<AbstractEnum> $cases
     *
     * @return list<string>
     */
    private function values(array $cases): array
    {
        return array_map(static fn (AbstractEnum $case): string => $case->value(), $cases);
    }
}
