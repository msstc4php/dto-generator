<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Support\Extensions;

use LogicException;
use MSSTC4PHP\DtoGenerator\Contract\Extension;
use MSSTC4PHP\DtoGenerator\Contract\ExtensionRegistry;

final class NamelessExtension implements Extension
{
    public function name(): string
    {
        throw new LogicException('no name yet');
    }

    public function register(ExtensionRegistry $registry, array $config): void
    {
    }
}
