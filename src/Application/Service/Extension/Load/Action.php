<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Service\Extension\Load;

use Closure;
use MSSTC4PHP\DtoGenerator\Application\Extension\Registry;
use MSSTC4PHP\DtoGenerator\Application\Port\ExtensionFailed;
use MSSTC4PHP\DtoGenerator\Application\Port\ExtensionLoader;
use MSSTC4PHP\DtoGenerator\Contract\Extension;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;

/**
 * Registers the extensions of a run (spec §8): the built-in ones first, then those the config lists, in its order.
 */
final class Action
{
    private ExtensionLoader $loader;

    /** @var Closure(array<string, array<array-key, mixed>>): list<Extension> */
    private Closure $builtIn;

    /**
     * @param Closure(array<string, array<array-key, mixed>>): list<Extension> $builtIn the built-in extensions for the
     *                                                                                  config's attributeAliases
     */
    public function __construct(ExtensionLoader $loader, Closure $builtIn)
    {
        $this->loader = $loader;
        $this->builtIn = $builtIn;
    }

    public function __invoke(Input $input): Output
    {
        $config = $input->config();
        $settings = $config->extensions();
        $diagnostics = new Diagnostics();
        $registry = new Registry($diagnostics, $config->location());
        $sections = $settings->config();

        $extensions = ($this->builtIn)($settings->aliases());
        foreach ($settings->classes() as $index => $class) {
            try {
                $extensions[] = $this->loader->load($class);
            } catch (ExtensionFailed $exception) {
                $diagnostics->error(
                    sprintf('Extension %s cannot be loaded: %s', $class->fqcn(), $exception->getMessage()),
                    $config->location()->child('extensions', (string) $index),
                );
            }
        }

        foreach ($extensions as $extension) {
            $section = $sections[$extension->name()] ?? [];
            if (!is_array($section)) {
                $diagnostics->error(
                    sprintf('The config of extension "%s" must be an object or a list.', $extension->name()),
                    $config->location()->child('extensionConfig', $extension->name()),
                );
                $section = [];
            }

            $registry->register($extension, array_map([Json::class, 'value'], $section));
        }

        foreach (array_keys($settings->aliases()) as $alias) {
            if ($registry->isClaimed($alias)) {
                $diagnostics->error(sprintf('Alias "%s" is claimed by an extension.', $alias), $config->location()->child('attributeAliases', $alias));
            }
        }

        return new Output($registry, $diagnostics);
    }
}
