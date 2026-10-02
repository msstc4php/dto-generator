<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Support\Extensions;

use MSSTC4PHP\DtoGenerator\Contract\Extension;
use MSSTC4PHP\DtoGenerator\Contract\ExtensionRegistry;
use MSSTC4PHP\DtoGenerator\Contract\FormatMapping;
use MSSTC4PHP\DtoGenerator\Domain\Model\ScalarType;

final class MoneyFormatExtension implements Extension
{
    private string $type;

    /**
     * An optional argument still lets the loader create the extension.
     */
    public function __construct(string $type = 'numeric-string')
    {
        $this->type = $type;
    }

    public function name(): string
    {
        return 'money';
    }

    public function register(ExtensionRegistry $registry, array $config): void
    {
        $registry->addFormat('money', new FormatMapping(ScalarType::string($this->type)));
    }
}
