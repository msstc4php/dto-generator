<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Support;

use MSSTC4PHP\DtoGenerator\Application\Port\DiscoveredExtension;
use MSSTC4PHP\DtoGenerator\Application\Port\DiscoveredExtensions;
use MSSTC4PHP\DtoGenerator\Application\Port\ExtensionDiscovery;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;

/**
 * Installed packages that declare the given extensions, as package → classes.
 */
final class FixedExtensionDiscovery implements ExtensionDiscovery
{
    /** @var array<non-empty-string, list<string>> */
    private array $packages;

    /** @var list<string> */
    private array $problems;

    public int $calls = 0;

    /**
     * @param array<non-empty-string, list<string>> $packages
     * @param list<string> $problems
     */
    public function __construct(array $packages = [], array $problems = [])
    {
        $this->packages = $packages;
        $this->problems = $problems;
    }

    public function discover(): DiscoveredExtensions
    {
        $this->calls++;
        $extensions = [];
        foreach ($this->packages as $package => $classes) {
            foreach ($classes as $class) {
                $extensions[] = new DiscoveredExtension($package, ClassName::fromFqcn($class));
            }
        }

        return new DiscoveredExtensions($extensions, $this->problems);
    }
}
