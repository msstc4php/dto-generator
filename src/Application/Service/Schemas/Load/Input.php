<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Service\Schemas\Load;

use MSSTC4PHP\DtoGenerator\Application\Config\GeneratorConfig;
use MSSTC4PHP\DtoGenerator\Application\Port\RemotePolicy;

final class Input
{
    private GeneratorConfig $config;

    private RemotePolicy $remotePolicy;

    /** @var list<string> */
    private array $claimedKeywords;

    /**
     * @param RemotePolicy|null $remotePolicy the cache only by default
     * @param list<string> $claimedKeywords keywords an extension checks, so the parser does not warn about them
     */
    public function __construct(GeneratorConfig $config, ?RemotePolicy $remotePolicy = null, array $claimedKeywords = [])
    {
        $this->config = $config;
        $this->remotePolicy = $remotePolicy ?? RemotePolicy::from(RemotePolicy::CACHED);
        $this->claimedKeywords = $claimedKeywords;
    }

    /**
     * @return list<string>
     */
    public function claimedKeywords(): array
    {
        return $this->claimedKeywords;
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
