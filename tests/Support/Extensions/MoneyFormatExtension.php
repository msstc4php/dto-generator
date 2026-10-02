<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Support\Extensions;

use MSSTC4PHP\DtoGenerator\Contract\Extension;
use MSSTC4PHP\DtoGenerator\Contract\ExtensionRegistry;
use MSSTC4PHP\DtoGenerator\Contract\FormatMapping;
use MSSTC4PHP\DtoGenerator\Domain\Model\ScalarType;

final class MoneyFormatExtension implements Extension
{
    public function name(): string
    {
        return 'money';
    }

    public function register(ExtensionRegistry $registry, array $config): void
    {
        $registry->addFormat('money', new FormatMapping(ScalarType::string('numeric-string')));
    }
}
