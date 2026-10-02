<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Support\Extensions;

use MSSTC4PHP\DtoGenerator\Contract\Extension;
use MSSTC4PHP\DtoGenerator\Contract\ExtensionRegistry;
use RuntimeException;

final class ThrowingExtension implements Extension
{
    public function __construct()
    {
        throw new RuntimeException('no env');
    }

    public function name(): string
    {
        return 'throwing';
    }

    public function register(ExtensionRegistry $registry, array $config): void
    {
    }
}
