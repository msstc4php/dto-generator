<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Port;

interface ExtensionDiscovery
{
    /**
     * The extensions installed packages declare in `extra.dto-generator.extensions` (spec §8), in the order found.
     */
    public function discover(): DiscoveredExtensions;
}
