<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Support\Extensions;

use MSSTC4PHP\DtoGenerator\Contract\Extension;
use MSSTC4PHP\DtoGenerator\Contract\ExtensionRegistry;
use MSSTC4PHP\DtoGenerator\Contract\PropertyContext;
use MSSTC4PHP\DtoGenerator\Contract\PropertyEnricher;

/**
 * Claims `contains` and checks it on the properties whose name starts with "checked".
 */
final class ContainsCheckingExtension implements Extension, PropertyEnricher
{
    public function name(): string
    {
        return 'contains';
    }

    public function register(ExtensionRegistry $registry, array $config): void
    {
        $registry->claimKeywords('contains');
        $registry->addPropertyEnricher($this);
    }

    public function enrichProperty(PropertyContext $context): array
    {
        if (strncmp($context->property()->wireName(), 'checked', 7) === 0) {
            $context->keywordChecks()->checked($context->schema()->location(), 'contains');
        }

        return [];
    }
}
