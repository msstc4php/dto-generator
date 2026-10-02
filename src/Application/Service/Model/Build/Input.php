<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Service\Model\Build;

use MSSTC4PHP\DtoGenerator\Application\Config\GeneratorConfig;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassType;
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
     * @param array<int|string, TypeModel>|null $formats string formats the extensions and the config map; the config's alone by default
     * @param list<string> $aliases keys of attributeAliases, which classes and properties may carry
     */
    public function __construct(GeneratorConfig $config, TargetProfile $target, SchemaGraph $graph, ?array $formats = null, array $aliases = [])
    {
        $this->config = $config;
        $this->target = $target;
        $this->graph = $graph;
        $this->formats = $formats ?? array_map(static fn (ClassName $class): TypeModel => new ClassType($class), $config->formats());
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
