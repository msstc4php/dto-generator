<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Support\Extensions;

use MSSTC4PHP\DtoGenerator\Contract\Extension;
use MSSTC4PHP\DtoGenerator\Contract\ExtensionRegistry;

final class NeedsArgumentsExtension implements Extension
{
    private string $required;

    public function __construct(string $required)
    {
        $this->required = $required;
    }

    public function name(): string
    {
        return 'needs-' . $this->required;
    }

    public function register(ExtensionRegistry $registry, array $config): void
    {
    }
}
