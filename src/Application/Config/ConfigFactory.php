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
use MSSTC4PHP\DtoGenerator\Domain\Model\ScalarType;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Extensions;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Formats;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Reference;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use MSSTC4PHP\DtoGenerator\Domain\Shared\AbstractEnum;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Path;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Url;
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
        'extensions', 'extensionConfig', 'sources', 'remoteRefs',
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
        $remoteRefs = $this->remoteRefs($root->section('remoteRefs'), Path::directory($path));

        if (count($diagnostics->errors()) > $errors || $sources === []) {
            return null;
        }

        return new GeneratorConfig($path, $target, $dto, $formats, $extensions, $sources, $remoteRefs);
    }

    private function remoteRefs(RawSection $section, string $baseDir): RemoteRefsSettings
    {
        $section->rejectUnknownKeys(['allow', 'cacheDir', 'timeout', 'headers']);
        $allow = [];
        foreach ($section->stringList('allow', []) as $index => $prefix) {
            if (!Url::isUrl($prefix) || strpbrk($prefix, '?#') !== false) {
                $section->report('A remote $ref prefix must be an absolute http or https URL without query or fragment.', 'allow', (string) $index);

                continue;
            }

            try {
                $allow[] = Url::normalize($prefix);
            } catch (InvalidModel $exception) {
                $section->report($exception->getMessage(), 'allow', (string) $index);
            }
        }

        $cacheDir = $section->has('cacheDir') ? $section->raw('cacheDir') : RemoteRefsSettings::CACHE_DIR;
        if (!is_string($cacheDir) || $cacheDir === '') {
            $section->error('cacheDir', 'must be a non-empty string');
            $cacheDir = RemoteRefsSettings::CACHE_DIR;
        }

        $timeout = $section->has('timeout') ? $section->raw('timeout') : RemoteRefsSettings::TIMEOUT;
        if (!is_int($timeout) || $timeout < 1 || $timeout > 300) {
            $section->error('timeout', 'must be a number of seconds from 1 to 300');
            $timeout = RemoteRefsSettings::TIMEOUT;
        }

        return new RemoteRefsSettings($allow, Path::resolve($baseDir, $cacheDir), $timeout, $section->has('headers') ? $this->remoteHeaders($section, $allow) : []);
    }

    /**
     * @param list<string> $allow the normalized prefixes remote $refs may name
     *
     * @return list<RemoteHeader>
     */
    private function remoteHeaders(RawSection $section, array $allow): array
    {
        $headers = [];
        $seen = [];
        foreach ($section->sectionList('headers') as $header) {
            $header->rejectUnknownKeys(['prefix', 'name', 'value']);
            $prefix = $header->requiredString('prefix');
            $name = $header->requiredString('name');
            $value = $header->requiredString('value');
            if ($prefix === null || $name === null || $value === null) {
                continue;
            }

            if (!Url::isUrl($prefix) || strpbrk($prefix, '?#') !== false) {
                $header->report('A header prefix must be an absolute https URL without query or fragment.', 'prefix');

                continue;
            }

            try {
                $prefix = Url::normalize($prefix);
            } catch (InvalidModel $exception) {
                $header->report($exception->getMessage(), 'prefix');

                continue;
            }

            if (substr($prefix, 0, 8) !== 'https://') {
                $header->report(sprintf('A header is sent only over https, not to "%s".', $prefix), 'prefix');

                continue;
            }

            if (isset($seen[$prefix][strtolower($name)])) {
                $header->report(sprintf('Another entry already sets %s for %s.', $seen[$prefix][strtolower($name)], $prefix));

                continue;
            }

            try {
                $headers[] = new RemoteHeader($prefix, $name, $value);
                $seen[$prefix][strtolower($name)] = $name;
            } catch (InvalidArgumentException $exception) {
                $header->report($exception->getMessage());

                continue;
            }

            if (!$this->meetsAnyOf($prefix, $allow)) {
                $header->warn(sprintf('No remoteRefs.allow prefix overlaps %s, so the header %s is never sent.', $prefix, $name), 'prefix');
            }
        }

        return $headers;
    }

    /**
     * Whether a URL lies under one of the prefixes or one of them under it.
     *
     * @param list<string> $prefixes
     */
    private function meetsAnyOf(string $url, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (Url::isUnder($url, $prefix) || Url::isUnder($prefix, $url)) {
                return true;
            }
        }

        return false;
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
     * A class, or a scalar the format is known for: the property keeps the type its schema gives.
     *
     * @return array<int|string, ClassName|ScalarType>
     */
    private function formats(RawSection $section): array
    {
        $scalars = $this->scalars();
        $formats = [];
        $names = [];
        foreach ($section->keys() as $name) {
            $normalized = Formats::normalize((string) $name);
            if (isset($names[$normalized])) {
                $section->report(sprintf('"%s" and "%s" name one format; keep one of them.', $names[$normalized], $name), (string) $name);

                continue;
            }

            $names[$normalized] = $name;
            $entry = $section->section($name);
            $entry->rejectUnknownKeys(['type']);
            $type = $entry->requiredString('type');
            if ($type === null) {
                continue;
            }

            $scalar = $scalars[strtolower($type)] ?? null;
            if ($scalar instanceof ScalarType) {
                $formats[$name] = $scalar;

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

    /**
     * PHP's names of the scalars and JSON Schema's; neither is a class anyone means.
     *
     * @return array<string, ScalarType>
     */
    private function scalars(): array
    {
        $integer = ScalarType::int();
        $float = ScalarType::float();
        $boolean = ScalarType::bool();

        return ['string' => ScalarType::string(), 'int' => $integer, 'integer' => $integer, 'float' => $float, 'number' => $float, 'bool' => $boolean, 'boolean' => $boolean];
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

        [$discover, $excluded] = $this->discovery($root);

        return new ExtensionSettings($classes, $discover, $config, $aliases, is_bool($verify) ? $verify : null, $excluded);
    }

    /**
     * discoverExtensions: true, false or an object of what discovery leaves out, which turns it on.
     *
     * @return array{bool, list<ExcludedExtension>}
     */
    private function discovery(RawSection $root): array
    {
        $raw = $root->raw('discoverExtensions');
        if (is_array($raw) && ($raw === [] || !Json::isList($raw))) {
            $section = $root->section('discoverExtensions');
            $section->rejectUnknownKeys(['exclude']);

            $excluded = [];
            foreach ($section->stringList('exclude', []) as $index => $entry) {
                $excluded = array_merge($excluded, $this->excludedExtension($index, $entry, $section));
            }

            return [true, $excluded];
        }

        if ($root->has('discoverExtensions') && !is_bool($raw)) {
            $root->error('discoverExtensions', 'must be true, false or an object with "exclude"');
        }

        return [$raw !== false, []];
    }

    /**
     * @param non-negative-int $index
     *
     * @return list<ExcludedExtension> the entry, or none when it names neither a package nor a class
     */
    private function excludedExtension(int $index, string $entry, RawSection $section): array
    {
        // Composer's own pattern for a package name.
        if (preg_match('{^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*$}D', $entry) === 1) {
            return [ExcludedExtension::package($index, $entry)];
        }

        try {
            return [ExcludedExtension::extension($index, ClassName::fromFqcn($entry))];
        } catch (InvalidModel $exception) {
            $section->report(sprintf('Expected a package name in lower case (vendor/name) or an extension class: %s', $exception->getMessage()), 'exclude', (string) $index);

            return [];
        }
    }

    /**
     * `true` for every operation, or the include and exclude globs over their names.
     */
    private function operations(RawSection $source): ?OperationFilter
    {
        $raw = $source->raw('operations');
        if ($raw === null || is_bool($raw)) {
            return $raw === true ? OperationFilter::all() : null;
        }

        if (!is_array($raw) || ($raw !== [] && Json::isList($raw))) {
            $source->error('operations', 'must be true, false or an object of "include" and "exclude"');

            return null;
        }

        $section = $source->section('operations');
        $section->rejectUnknownKeys(['include', 'exclude']);

        $include = $section->stringList('include', ['*']);
        $exclude = $section->stringList('exclude', []);
        if ($section->raw('include') === []) {
            $section->error('include', 'must not be empty');
        }

        // An include with no valid pattern is reported already.
        return $include === [] ? null : new OperationFilter($include, $exclude);
    }

    /**
     * @return list<SourceConfig>
     */
    private function sources(RawSection $root, string $baseDir): array
    {
        $sources = [];
        foreach ($root->sectionList('sources') as $section) {
            $section->rejectUnknownKeys(['spec', 'namespace', 'outputDir', 'include', 'exclude', 'classNames', 'propertyNames', 'operations']);
            $spec = $section->requiredString('spec');
            $namespace = $section->requiredString('namespace');
            $outputDir = $section->requiredString('outputDir');
            $include = array_values($section->stringList('include', ['*']));
            $exclude = array_values($section->stringList('exclude', []));
            $operations = $this->operations($section);

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

            // Checked before the rest of the source, so every error of it shows at once.
            $specPath = $spec === null ? null : Path::resolve($baseDir, $spec);
            $names = array_merge(
                $this->nameOverrides($section, 'classNames', NameOverride::CLASS_NAME, $specPath),
                $this->nameOverrides($section, 'propertyNames', NameOverride::PROPERTY_NAME, $specPath),
            );
            if ($specPath === null || $namespace === null || $outputDir === null || $include === []) {
                continue;
            }

            $sources[] = new SourceConfig($specPath, $namespace, Path::resolve($baseDir, $outputDir), $include, $exclude, $names, $operations);
        }

        return $sources;
    }

    /**
     * @param NameOverride::CLASS_NAME|NameOverride::PROPERTY_NAME $keyword
     *
     * @return list<NameOverride>
     */
    private function nameOverrides(RawSection $source, string $key, string $keyword, ?string $spec): array
    {
        if (!$source->has($key)) {
            return [];
        }

        $section = $source->section($key);
        $overrides = [];
        foreach ($section->keys() as $ref) {
            $name = $section->raw($ref);
            if (!is_string($name) || $name === '') {
                $section->report('Expected a non-empty string.', $ref);

                continue;
            }

            try {
                // Without a specification the key is still checked, against any path.
                $target = Reference::target($ref, new SchemaLocation($spec ?? '/'));
            } catch (InvalidModel $exception) {
                $section->report(sprintf('Expected a reference to a schema of the specification, like "#/components/schemas/Pet" or "#/definitions/Pet": %s', $exception->getMessage()), $ref);

                continue;
            }

            if ($spec !== null) {
                $overrides[] = new NameOverride($keyword, $target, $name, $section->location($ref));
            }
        }

        return $overrides;
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
