<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Port;

final class DiscoveredExtensions
{
    /** @var list<DiscoveredExtension> */
    private array $extensions;

    /** @var list<string> */
    private array $problems;

    /**
     * @param list<DiscoveredExtension> $extensions
     * @param list<string> $problems declarations that were skipped, as messages
     */
    public function __construct(array $extensions, array $problems = [])
    {
        $this->extensions = $extensions;
        $this->problems = $problems;
    }

    /**
     * @return list<DiscoveredExtension>
     */
    public function extensions(): array
    {
        return $this->extensions;
    }

    /**
     * @return list<string>
     */
    public function problems(): array
    {
        return $this->problems;
    }
}
