<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Service\Model\Build;

use MSSTC4PHP\DtoGenerator\Application\Config\GeneratorConfig;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaGraph;
use MSSTC4PHP\DtoGenerator\Domain\Target\TargetProfile;

final class Input
{
    private GeneratorConfig $config;

    private TargetProfile $target;

    private SchemaGraph $graph;

    public function __construct(GeneratorConfig $config, TargetProfile $target, SchemaGraph $graph)
    {
        $this->config = $config;
        $this->target = $target;
        $this->graph = $graph;
    }

    public function config(): GeneratorConfig
    {
        return $this->config;
    }

    public function target(): TargetProfile
    {
        return $this->target;
    }

    public function graph(): SchemaGraph
    {
        return $this->graph;
    }
}
