<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Service\Schemas\Load;

use MSSTC4PHP\DtoGenerator\Application\Config\GeneratorConfig;

final class Input
{
    private GeneratorConfig $config;

    private bool $fetchesRemote;

    /**
     * @param bool $fetchesRemote whether a remote document missing from the cache may be fetched (a run that writes)
     */
    public function __construct(GeneratorConfig $config, bool $fetchesRemote = false)
    {
        $this->config = $config;
        $this->fetchesRemote = $fetchesRemote;
    }

    public function fetchesRemote(): bool
    {
        return $this->fetchesRemote;
    }

    public function config(): GeneratorConfig
    {
        return $this->config;
    }
}
