<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Service\Extension\Load;

use Closure;
use MSSTC4PHP\DtoGenerator\Application\Config\GeneratorConfig;
use MSSTC4PHP\DtoGenerator\Application\Extension\Registry;
use MSSTC4PHP\DtoGenerator\Application\Port\ExtensionDiscovery;
use MSSTC4PHP\DtoGenerator\Application\Port\ExtensionFailed;
use MSSTC4PHP\DtoGenerator\Application\Port\ExtensionLoader;
use MSSTC4PHP\DtoGenerator\Contract\Extension;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Model\AttributeModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\Identifier;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;
use Throwable;

/**
 * Registers the extensions of a run (spec §8): the built-in ones first, then those the config lists, in its order, then
 * those installed packages declare.
 *
 * @phpstan-import-type AttributeDeclaration from AttributeModel
 */
final class Action
{
    private ExtensionLoader $loader;

    /** @var Closure(array<string, AttributeDeclaration>): list<Extension> */
    private Closure $builtIn;

    private ExtensionDiscovery $discovery;

    /**
     * @param Closure(array<string, AttributeDeclaration>): list<Extension> $builtIn the built-in extensions for the
     *                                                                               config's attributeAliases
     */
    public function __construct(ExtensionLoader $loader, Closure $builtIn, ExtensionDiscovery $discovery)
    {
        $this->loader = $loader;
        $this->builtIn = $builtIn;
        $this->discovery = $discovery;
    }

    public function __invoke(Input $input): Output
    {
        $config = $input->config();
        $diagnostics = new Diagnostics();
        $registry = new Registry($diagnostics, $config->location());

        $named = $this->registerOnce($this->load($config, $diagnostics), $config, $registry, $diagnostics);
        foreach (array_keys($config->extensions()->config()) as $name) {
            if (!isset($named[$name])) {
                $diagnostics->warning(
                    sprintf('No loaded extension is named "%s", so this config is not used.', $name),
                    $config->location()->child('extensionConfig', (string) $name),
                );
            }
        }

        foreach (array_keys($config->extensions()->aliases()) as $alias) {
            if ($registry->isClaimed($alias)) {
                $diagnostics->error(sprintf('Alias "%s" is claimed by an extension.', $alias), $config->location()->child('attributeAliases', $alias));
            }
        }

        return new Output($registry, $diagnostics);
    }

    /**
     * The built-in extensions, then the configured ones, then the discovered ones by package (spec §8), each with where
     * it comes from and its class.
     *
     * @return list<array{Extension, SchemaLocation, string}>
     */
    private function load(GeneratorConfig $config, Diagnostics $diagnostics): array
    {
        $settings = $config->extensions();
        $loaded = array_map(static fn (Extension $extension): array => [$extension, $config->location(), get_class($extension)], ($this->builtIn)($settings->aliases()));
        $listed = [];
        foreach ($loaded as [, , $class]) {
            $listed[Identifier::asciiLower($class)] = true;
        }

        foreach ($settings->classes() as $index => $class) {
            $listed[Identifier::asciiLower($class->fqcn())] = true;
            $at = $config->location()->child('extensions', (string) $index);
            try {
                $loaded[] = [$this->loader->load($class), $at, $class->fqcn()];
            } catch (ExtensionFailed $exception) {
                $diagnostics->error(sprintf('Extension %s cannot be loaded: %s', $class->fqcn(), $exception->getMessage()), $at);
            }
        }

        return $settings->discover() ? array_merge($loaded, $this->discovered($config, $listed, $diagnostics)) : $loaded;
    }

    /**
     * @param array<string, true> $listed lower-cased classes already loaded, which discovery does not load again
     *
     * @return list<array{Extension, SchemaLocation, string}>
     */
    private function discovered(GeneratorConfig $config, array $listed, Diagnostics $diagnostics): array
    {
        $at = $config->location()->child('discoverExtensions');
        $found = $this->discovery->discover();
        foreach ($found->problems() as $problem) {
            $diagnostics->warning($problem, $at);
        }

        $extensions = $found->extensions();
        // usort() is stable only from PHP 8.0; the index keeps a package's own order on 7.4.
        $order = array_keys($extensions);
        usort($order, static fn (int $a, int $b): int => [$extensions[$a]->package(), $a] <=> [$extensions[$b]->package(), $b]);

        $loaded = [];
        foreach ($order as $index) {
            $discovered = $extensions[$index];
            $class = $discovered->className();
            $key = Identifier::asciiLower($class->fqcn());
            if (isset($listed[$key])) {
                continue;
            }

            $listed[$key] = true;
            try {
                $loaded[] = [$this->loader->load($class), $at, $class->fqcn()];
            } catch (ExtensionFailed $exception) {
                $diagnostics->error(sprintf('Extension %s, discovered in package %s, cannot be loaded: %s', $class->fqcn(), $discovered->package(), $exception->getMessage()), $at);
            }
        }

        return $loaded;
    }

    /**
     * Registers each extension under its own name, with its section of extensionConfig.
     *
     * @param list<array{Extension, SchemaLocation, string}> $loaded
     *
     * @return array<string, string> the names registered
     */
    private function registerOnce(array $loaded, GeneratorConfig $config, Registry $registry, Diagnostics $diagnostics): array
    {
        $sections = $config->extensions()->config();
        $named = [];
        foreach ($loaded as [$extension, $at, $class]) {
            try {
                $name = $extension->name();
            } catch (Throwable $exception) {
                $diagnostics->error(sprintf('Extension %s failed to give its name: %s', $class, $exception->getMessage()), $at);

                continue;
            }

            if (isset($named[$name])) {
                $diagnostics->error(sprintf('Extension %s is named "%s" like an extension before it; it is not used.', $class, $name), $at);

                continue;
            }

            $named[$name] = $name;
            $section = $sections[$name] ?? [];
            if (!is_array($section)) {
                $diagnostics->error(sprintf('The config of extension "%s" must be an object or a list.', $name), $config->location()->child('extensionConfig', $name));
                $section = [];
            }

            $registry->register($extension, array_map([Json::class, 'value'], $section));
        }

        return $named;
    }
}
