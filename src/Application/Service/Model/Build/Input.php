<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Service\Model\Build;

use MSSTC4PHP\DtoGenerator\Application\Config\GeneratorConfig;
use MSSTC4PHP\DtoGenerator\Domain\Model\TypeModel;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaGraph;
use MSSTC4PHP\DtoGenerator\Domain\Target\TargetProfile;

final class Input
{
    private GeneratorConfig $config;

    private TargetProfile $target;

    private SchemaGraph $graph;

    /** @var array<int|string, TypeModel> */
    private array $formats;

    /** @var list<string> */
    private array $aliases;

    /**
     * @param array<int|string, TypeModel> $formats string formats the config and the extensions map
     * @param list<string> $aliases keys of attributeAliases, which classes and properties may carry
     */
    public function __construct(GeneratorConfig $config, TargetProfile $target, SchemaGraph $graph, array $formats, array $aliases = [])
    {
        $this->config = $config;
        $this->target = $target;
        $this->graph = $graph;
        $this->formats = $formats;
        $this->aliases = $aliases;
    }

    /**
     * @return array<int|string, TypeModel>
     */
    public function formats(): array
    {
        return $this->formats;
    }

    /**
     * @return list<string>
     */
    public function aliases(): array
    {
        return $this->aliases;
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
