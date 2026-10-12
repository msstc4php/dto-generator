<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Support\Extensions;

use MSSTC4PHP\DtoGenerator\Contract\Extension;
use MSSTC4PHP\DtoGenerator\Contract\ExtensionRegistry;

/**
 * Claims `contains`, as an extension that checks it would.
 */
final class ContainsCheckingExtension implements Extension
{
    public function name(): string
    {
        return 'contains';
    }

    public function register(ExtensionRegistry $registry, array $config): void
    {
        $registry->claimKeywords('contains');
    }
}
