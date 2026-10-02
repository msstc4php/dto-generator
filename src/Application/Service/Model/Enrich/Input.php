<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Service\Model\Enrich;

use MSSTC4PHP\DtoGenerator\Application\Extension\Registry;
use MSSTC4PHP\DtoGenerator\Application\Port\ClassVerifier;
use MSSTC4PHP\DtoGenerator\Application\Service\Model\Build\BuiltClass;
use MSSTC4PHP\DtoGenerator\Application\Service\Model\Build\BuiltEnum;
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

    private ?ClassVerifier $verifier;

    /** @var list<BuiltEnum> */
    private array $enums;

    /**
     * @param list<BuiltClass> $classes
     * @param list<BuiltEnum> $enums the enums of this run, which exist for attributes though the autoloader lacks them
     * @param ClassVerifier|null $verifier the consumer's autoloader when verifyClasses is on
     */
    public function __construct(array $classes, array $enums, SchemaGraph $graph, TargetProfile $target, Registry $registry, InstalledPackages $packages, ?ClassVerifier $verifier = null)
    {
        $this->verifier = $verifier;
        $this->enums = $enums;
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

    /**
     * @return list<BuiltEnum>
     */
    public function enums(): array
    {
        return $this->enums;
    }

    public function verifier(): ?ClassVerifier
    {
        return $this->verifier;
    }

    public function packages(): InstalledPackages
    {
        return $this->packages;
    }
}
