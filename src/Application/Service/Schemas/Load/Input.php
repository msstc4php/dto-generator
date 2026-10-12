<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Service\Schemas\Load;

use MSSTC4PHP\DtoGenerator\Application\Config\GeneratorConfig;
use MSSTC4PHP\DtoGenerator\Application\Port\RemotePolicy;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\KeywordChecks;

final class Input
{
    private GeneratorConfig $config;

    private RemotePolicy $remotePolicy;

    private KeywordChecks $keywordChecks;

    /**
     * @param RemotePolicy|null $remotePolicy the cache only by default
     * @param KeywordChecks|null $keywordChecks the keywords extensions check, none by default
     */
    public function __construct(GeneratorConfig $config, ?RemotePolicy $remotePolicy = null, ?KeywordChecks $keywordChecks = null)
    {
        $this->config = $config;
        $this->remotePolicy = $remotePolicy ?? RemotePolicy::from(RemotePolicy::CACHED);
        $this->keywordChecks = $keywordChecks ?? new KeywordChecks();
    }

    public function keywordChecks(): KeywordChecks
    {
        return $this->keywordChecks;
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
