<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Contract;

use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\PropertyModel;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;
use MSSTC4PHP\DtoGenerator\Domain\Target\TargetProfile;

/**
 * What a property enricher may read; it reports through the diagnostics and returns attributes instead of changing the IR.
 */
final class PropertyContext
{
    private PropertyModel $property;

    private ClassModel $owner;

    private Schema $schema;

    private TargetProfile $target;

    private InstalledPackages $packages;

    private Diagnostics $diagnostics;

    public function __construct(PropertyModel $property, ClassModel $owner, Schema $schema, TargetProfile $target, InstalledPackages $packages, Diagnostics $diagnostics)
    {
        $this->property = $property;
        $this->owner = $owner;
        $this->schema = $schema;
        $this->target = $target;
        $this->packages = $packages;
        $this->diagnostics = $diagnostics;
    }

    public function property(): PropertyModel
    {
        return $this->property;
    }

    public function owner(): ClassModel
    {
        return $this->owner;
    }

    /**
     * The property's own schema, with every keyword and `x-*` extension.
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
