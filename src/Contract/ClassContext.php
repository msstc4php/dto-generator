<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Contract;

use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\KeywordChecks;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\DiscriminatorModel;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;
use MSSTC4PHP\DtoGenerator\Domain\Target\TargetProfile;

/**
 * What a class enricher may read; it reports through the diagnostics and returns attributes instead of changing the IR.
 *
 * @api
 */
final class ClassContext
{
    private ClassModel $class;

    private Schema $schema;

    private TargetProfile $target;

    private InstalledPackages $packages;

    private Diagnostics $diagnostics;

    private SchemaReferences $references;

    private ?DiscriminatorModel $selectingDiscriminator;

    private bool $inline;

    private KeywordChecks $keywordChecks;

    /** @var list<ClassModel> */
    private array $ancestors;

    /**
     * @param bool $inline whether the class comes from an object written inside a property, whose schema is the property's
     * @param SchemaReferences|null $references how `$ref`s resolve; none when omitted
     * @param DiscriminatorModel|null $selectingDiscriminator the discriminator whose mapping selects this class, if any
     * @param KeywordChecks|null $keywordChecks the claimed keywords of the run; none when omitted
     * @param list<ClassModel> $ancestors the classes the class extends, nearest first
     */
    public function __construct(ClassModel $class, Schema $schema, TargetProfile $target, InstalledPackages $packages, Diagnostics $diagnostics, bool $inline = false, ?SchemaReferences $references = null, ?DiscriminatorModel $selectingDiscriminator = null, ?KeywordChecks $keywordChecks = null, array $ancestors = [])
    {
        $this->class = $class;
        $this->schema = $schema;
        $this->target = $target;
        $this->packages = $packages;
        $this->diagnostics = $diagnostics;
        $this->inline = $inline;
        $this->references = $references ?? SchemaReferences::none();
        $this->selectingDiscriminator = $selectingDiscriminator;
        $this->keywordChecks = $keywordChecks ?? new KeywordChecks();
        $this->ancestors = $ancestors;
    }

    /**
     * The classes the class extends, nearest first, as the run builds them, before enrichment: each declares its own
     * properties, which the class inherits. The list stops at a parent the run does not generate.
     *
     * @return list<ClassModel>
     */
    public function ancestors(): array
    {
        return $this->ancestors;
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
     * The discriminator of the nearest ancestor whose mapping lists this class, so its values that name the class
     * select it; null for a class no discriminator selects. Instances of an intermediate class may also carry the
     * values of its descendants.
     */
    public function selectingDiscriminator(): ?DiscriminatorModel
    {
        return $this->selectingDiscriminator;
    }

    /**
     * Where the extension marks the claimed keywords it checked; see ExtensionRegistry::claimKeywords().
     */
    public function keywordChecks(): KeywordChecks
    {
        return $this->keywordChecks;
    }
}
