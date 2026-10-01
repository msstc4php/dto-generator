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

        if ($metadata->equals(MetadataMode::from(MetadataMode::ATTRIBUTES))) {
            $this->assertSupports(Capability::from(Capability::ATTRIBUTES), 'Metadata mode "attributes"');
        }

        $this->accessorsFor($mutability);
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
        $readonly = Capability::from(Capability::READONLY_PROPERTIES);
        $publicProperties = AccessorStyle::from(AccessorStyle::PUBLIC_PROPERTIES);

        if ($this->accessors->equals(AccessorStyle::from(AccessorStyle::AUTO))) {
            return $mutability->isImmutable() && $this->supports($readonly)
                ? $publicProperties
                : AccessorStyle::from(AccessorStyle::GETTERS);
        }

        if ($this->accessors->equals($publicProperties) && $mutability->isImmutable()) {
            $this->assertSupports($readonly, 'Immutable DTOs with public properties');
        }

        return $this->accessors;
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

    public function accessors(): AccessorStyle
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
}
