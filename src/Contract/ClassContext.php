<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Contract;

use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassModel;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;
use MSSTC4PHP\DtoGenerator\Domain\Target\TargetProfile;

/**
 * What a class enricher may read; it reports through the diagnostics and returns attributes instead of changing the IR.
 */
final class ClassContext
{
    private ClassModel $class;

    private Schema $schema;

    private TargetProfile $target;

    private InstalledPackages $packages;

    private Diagnostics $diagnostics;

    public function __construct(ClassModel $class, Schema $schema, TargetProfile $target, InstalledPackages $packages, Diagnostics $diagnostics)
    {
        $this->class = $class;
        $this->schema = $schema;
        $this->target = $target;
        $this->packages = $packages;
        $this->diagnostics = $diagnostics;
    }

    /**
     * Includes the parent and the discriminator, for a bridge that maps variants.
     */
    public function class(): ClassModel
    {
        return $this->class;
    }

    /**
     * The schema the class is generated from.
     */
    public function schema(): Schema
    {
        return $this->schema;
    }

    public function target(): TargetProfile
    {
        return $this->target;
    }

    public function packages(): InstalledPackages
    {
        return $this->packages;
    }

    public function diagnostics(): Diagnostics
    {
        return $this->diagnostics;
    }
}
