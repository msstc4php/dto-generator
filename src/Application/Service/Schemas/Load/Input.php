<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Service\Schemas\Load;

use MSSTC4PHP\DtoGenerator\Application\Config\GeneratorConfig;
use MSSTC4PHP\DtoGenerator\Application\Port\RemotePolicy;

final class Input
{
    private GeneratorConfig $config;

    private RemotePolicy $remotePolicy;

    /**
     * @param RemotePolicy|null $remotePolicy the cache only by default
     */
    public function __construct(GeneratorConfig $config, ?RemotePolicy $remotePolicy = null)
    {
        $this->config = $config;
        $this->remotePolicy = $remotePolicy ?? RemotePolicy::from(RemotePolicy::CACHED);
    }

    public function remotePolicy(): RemotePolicy
    {
        return $this->remotePolicy;
    }

    public function config(): GeneratorConfig
    {
        return $this->config;
    }
}
