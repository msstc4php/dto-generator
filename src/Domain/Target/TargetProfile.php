<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Target;

use MSSTC4PHP\DtoGenerator\Domain\Exception\IncompatibleTarget;

final class TargetProfile
{
    private PhpVersion $php;

    private MetadataMode $metadata;

    private Mutability $mutability;

    private AccessorStyle $accessors;

    private DateTimeClass $dateTimeClass;

    private bool $strict;

    public function __construct(
        PhpVersion $php,
        MetadataMode $metadata,
        Mutability $mutability,
        AccessorStyle $accessors,
        DateTimeClass $dateTimeClass,
        bool $strict
    ) {
        $this->php = $php;
        $this->metadata = $metadata;
        $this->mutability = $mutability;
        $this->accessors = $accessors;
        $this->dateTimeClass = $dateTimeClass;
        $this->strict = $strict;

        if ($metadata->isAttributes()) {
            $this->assertSupports(Capability::from(Capability::ATTRIBUTES), 'Metadata mode "attributes"');
        }

        $this->assertAccessorsCompatible($mutability);
    }

    public function supports(Capability $capability): bool
    {
        return $this->php->isAtLeast($capability->minimumVersion());
    }

    /**
     * Mutability can be overridden per schema, so the style is resolved per class, never globally.
     */
    public function accessorsFor(Mutability $mutability): AccessorStyle
    {
        if ($this->accessors->isAuto()) {
            return $mutability->isImmutable() && $this->supports(Capability::from(Capability::READONLY_PROPERTIES))
                ? AccessorStyle::from(AccessorStyle::PUBLIC_PROPERTIES)
                : AccessorStyle::from(AccessorStyle::GETTERS);
        }

        $this->assertAccessorsCompatible($mutability);

        return $this->accessors;
    }

    public function classFormFor(Mutability $mutability): ClassForm
    {
        $public = $this->accessorsFor($mutability)->equals(AccessorStyle::from(AccessorStyle::PUBLIC_PROPERTIES));
        $promoted = $this->supports(Capability::from(Capability::CONSTRUCTOR_PROMOTION));
        if (!$mutability->isImmutable()) {
            return new ClassForm($promoted, $public, false, false, !$public, !$public, WitherStyle::from(WitherStyle::NONE));
        }

        $readonly = $this->supports(Capability::from(Capability::READONLY_PROPERTIES));
        $readonlyClass = $this->supports(Capability::from(Capability::READONLY_CLASSES));
        if ($this->supports(Capability::from(Capability::CLONE_WITH))) {
            $withers = WitherStyle::CLONE_WITH;
        } elseif ($readonly) {
            // Readonly properties cannot be assigned on a clone before 8.5, so the copy goes through the constructor.
            $withers = WitherStyle::NEW_SELF;
        } else {
            $withers = WitherStyle::CLONE_ASSIGN;
        }

        return new ClassForm($promoted, $public, $readonly && !$readonlyClass, $readonlyClass, !$public, false, WitherStyle::from($withers));
    }

    public function php(): PhpVersion
    {
        return $this->php;
    }

    public function metadata(): MetadataMode
    {
        return $this->metadata;
    }

    public function mutability(): Mutability
    {
        return $this->mutability;
    }

    /**
     * As configured, possibly AUTO; generation must use {@see accessorsFor()}.
     */
    public function configuredAccessors(): AccessorStyle
    {
        return $this->accessors;
    }

    public function dateTimeClass(): DateTimeClass
    {
        return $this->dateTimeClass;
    }

    public function isStrict(): bool
    {
        return $this->strict;
    }

    private function assertSupports(Capability $capability, string $feature): void
    {
        if (!$this->supports($capability)) {
            throw IncompatibleTarget::capabilityMissing($capability, $this->php, $feature);
        }
    }

    private function assertAccessorsCompatible(Mutability $mutability): void
    {
        if ($mutability->isImmutable() && $this->accessors->equals(AccessorStyle::from(AccessorStyle::PUBLIC_PROPERTIES))) {
            $this->assertSupports(Capability::from(Capability::READONLY_PROPERTIES), 'Immutable DTOs with public properties');
        }
    }
}
