<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Port;

use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;

final class DiscoveredExtension
{
    /** @var non-empty-string */
    private string $package;

    private ClassName $className;

    /**
     * @param non-empty-string $package
     */
    public function __construct(string $package, ClassName $className)
    {
        $this->package = $package;
        $this->className = $className;
    }

    /**
     * @return non-empty-string
     */
    public function package(): string
    {
        return $this->package;
    }

    public function className(): ClassName
    {
        return $this->className;
    }
}
