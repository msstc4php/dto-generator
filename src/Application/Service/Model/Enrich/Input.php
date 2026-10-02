<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Service\Model\Enrich;

use MSSTC4PHP\DtoGenerator\Application\Extension\Registry;
use MSSTC4PHP\DtoGenerator\Application\Service\Model\Build\BuiltClass;
use MSSTC4PHP\DtoGenerator\Contract\InstalledPackages;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaGraph;
use MSSTC4PHP\DtoGenerator\Domain\Target\TargetProfile;

final class Input
{
    /** @var list<BuiltClass> */
    private array $classes;

    private SchemaGraph $graph;

    private TargetProfile $target;

    private Registry $registry;

    private InstalledPackages $packages;

    /**
     * @param list<BuiltClass> $classes
     */
    public function __construct(array $classes, SchemaGraph $graph, TargetProfile $target, Registry $registry, InstalledPackages $packages)
    {
        $this->classes = $classes;
        $this->graph = $graph;
        $this->target = $target;
        $this->registry = $registry;
        $this->packages = $packages;
    }

    /**
     * @return list<BuiltClass>
     */
    public function classes(): array
    {
        return $this->classes;
    }

    public function graph(): SchemaGraph
    {
        return $this->graph;
    }

    public function target(): TargetProfile
    {
        return $this->target;
    }

    public function registry(): Registry
    {
        return $this->registry;
    }

    public function packages(): InstalledPackages
    {
        return $this->packages;
    }
}
