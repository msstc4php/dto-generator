<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Service\Schemas\Load;

use MSSTC4PHP\DtoGenerator\Application\Config\GeneratorConfig;
use MSSTC4PHP\DtoGenerator\Application\Port\RemoteRequest;

final class Input
{
    private GeneratorConfig $config;

    /** @var RemoteRequest::CACHED|RemoteRequest::MISSING|RemoteRequest::ALL */
    private string $remotePolicy;

    /**
     * @param RemoteRequest::CACHED|RemoteRequest::MISSING|RemoteRequest::ALL $remotePolicy which remote documents may be
     *                                                                                      fetched: none, those missing from the cache (a run that writes) or all of them (a refresh)
     */
    public function __construct(GeneratorConfig $config, string $remotePolicy = RemoteRequest::CACHED)
    {
        $this->config = $config;
        $this->remotePolicy = $remotePolicy;
    }

    /**
     * @return RemoteRequest::CACHED|RemoteRequest::MISSING|RemoteRequest::ALL
     */
    public function remotePolicy(): string
    {
        return $this->remotePolicy;
    }

    public function config(): GeneratorConfig
    {
        return $this->config;
    }
}
