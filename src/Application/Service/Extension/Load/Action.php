<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Service\Extension\Load;

use Closure;
use MSSTC4PHP\DtoGenerator\Application\Config\GeneratorConfig;
use MSSTC4PHP\DtoGenerator\Application\Extension\Registry;
use MSSTC4PHP\DtoGenerator\Application\Port\ExtensionFailed;
use MSSTC4PHP\DtoGenerator\Application\Port\ExtensionLoader;
use MSSTC4PHP\DtoGenerator\Contract\Extension;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Model\AttributeModel;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;
use Throwable;

/**
 * Registers the extensions of a run (spec §8): the built-in ones first, then those the config lists, in its order.
 *
 * @phpstan-import-type AttributeDeclaration from AttributeModel
 */
final class Action
{
    private ExtensionLoader $loader;

    /** @var Closure(array<string, AttributeDeclaration>): list<Extension> */
    private Closure $builtIn;

    /**
     * @param Closure(array<string, AttributeDeclaration>): list<Extension> $builtIn the built-in extensions for the
     *                                                                               config's attributeAliases
     */
    public function __construct(ExtensionLoader $loader, Closure $builtIn)
    {
        $this->loader = $loader;
        $this->builtIn = $builtIn;
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
     * The built-in extensions, then the configured ones that load, each with its index in `extensions` (null if built in).
     *
     * @return list<array{Extension, int|null}>
     */
    private function load(GeneratorConfig $config, Diagnostics $diagnostics): array
    {
        $settings = $config->extensions();
        $loaded = array_map(static fn (Extension $extension): array => [$extension, null], ($this->builtIn)($settings->aliases()));
        foreach ($settings->classes() as $index => $class) {
            try {
                $loaded[] = [$this->loader->load($class), $index];
            } catch (ExtensionFailed $exception) {
                $diagnostics->error(
                    sprintf('Extension %s cannot be loaded: %s', $class->fqcn(), $exception->getMessage()),
                    $config->location()->child('extensions', (string) $index),
                );
            }
        }

        return $loaded;
    }

    /**
     * Registers each extension under its own name, with its section of extensionConfig.
     *
     * @param list<array{Extension, int|null}> $loaded
     *
     * @return array<string, string> the names registered
     */
    private function registerOnce(array $loaded, GeneratorConfig $config, Registry $registry, Diagnostics $diagnostics): array
    {
        $classes = $config->extensions()->classes();
        $sections = $config->extensions()->config();
        $named = [];
        foreach ($loaded as [$extension, $origin]) {
            $at = $origin === null ? $config->location() : $config->location()->child('extensions', (string) $origin);
            $class = $origin === null ? get_class($extension) : $classes[$origin]->fqcn();
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
