<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Contract;

use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\DiscriminatorModel;
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

    private SchemaReferences $references;

    private ?DiscriminatorModel $parentDiscriminator;

    private bool $inline;

    /**
     * @param bool $inline whether the class comes from an object written inside a property, whose schema is the property's
     * @param SchemaReferences|null $references how `$ref`s resolve; none when omitted
     * @param DiscriminatorModel|null $parentDiscriminator the discriminator of the parent class, if any
     */
    public function __construct(ClassModel $class, Schema $schema, TargetProfile $target, InstalledPackages $packages, Diagnostics $diagnostics, bool $inline = false, ?SchemaReferences $references = null, ?DiscriminatorModel $parentDiscriminator = null)
    {
        $this->class = $class;
        $this->schema = $schema;
        $this->target = $target;
        $this->packages = $packages;
        $this->diagnostics = $diagnostics;
        $this->inline = $inline;
        $this->references = $references ?? SchemaReferences::none();
        $this->parentDiscriminator = $parentDiscriminator;
    }

    /**
     * An inline class shares its schema with the property that holds it; keys like x-php-attributes belong to the property.
     */
    public function isInline(): bool
    {
        return $this->inline;
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

    public function references(): SchemaReferences
    {
        return $this->references;
    }

    /**
     * The discriminator of the class this one extends, whose mapping names the values that select it; null without a
     * parent or when the parent has none.
     */
    public function parentDiscriminator(): ?DiscriminatorModel
    {
        return $this->parentDiscriminator;
    }
}
