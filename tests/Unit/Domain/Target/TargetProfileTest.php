<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Target;

use MSSTC4PHP\DtoGenerator\Domain\Exception\IncompatibleTarget;
use MSSTC4PHP\DtoGenerator\Domain\Target\AccessorStyle;
use MSSTC4PHP\DtoGenerator\Domain\Target\Capability;
use MSSTC4PHP\DtoGenerator\Domain\Target\DateTimeClass;
use MSSTC4PHP\DtoGenerator\Domain\Target\MetadataMode;
use MSSTC4PHP\DtoGenerator\Domain\Target\Mutability;
use MSSTC4PHP\DtoGenerator\Domain\Target\PhpVersion;
use MSSTC4PHP\DtoGenerator\Domain\Target\TargetProfile;
use PHPUnit\Framework\TestCase;

final class TargetProfileTest extends TestCase
{
    public function testSupportsCapabilitiesUpToItsVersion(): void
    {
        $profile = $this->profile('8.1');

        self::assertTrue($profile->supports(Capability::from(Capability::ENUMS)));
        self::assertTrue($profile->supports(Capability::from(Capability::TYPED_PROPERTIES)));
        self::assertFalse($profile->supports(Capability::from(Capability::READONLY_CLASSES)));
    }

    public function testRejectsAttributeMetadataOnPhp74(): void
    {
        $this->expectException(IncompatibleTarget::class);
        $this->expectExceptionMessage('Metadata mode "attributes" requires attributes (PHP 8.0+), but the target is PHP 7.4.');

        $this->profile('7.4', MetadataMode::ATTRIBUTES);
    }

    public function testAllowsAnnotationsOnModernPhp(): void
    {
        self::assertSame(MetadataMode::from(MetadataMode::ANNOTATIONS), $this->profile('8.2', MetadataMode::ANNOTATIONS)->metadata());
    }

    /**
     * @dataProvider autoAccessors
     */
    public function testResolvesAutoAccessors(string $php, string $mutability, string $expected): void
    {
        self::assertSame(
            AccessorStyle::from($expected),
            $this->profile($php)->accessorsFor(Mutability::from($mutability)),
        );
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function autoAccessors(): array
    {
        return [
            'immutable on 8.1 uses readonly properties' => ['8.1', Mutability::IMMUTABLE, AccessorStyle::PUBLIC_PROPERTIES],
            'immutable on 8.0 needs getters' => ['8.0', Mutability::IMMUTABLE, AccessorStyle::GETTERS],
            'immutable on 7.4 needs getters' => ['7.4', Mutability::IMMUTABLE, AccessorStyle::GETTERS],
            'mutable always uses getters' => ['8.5', Mutability::MUTABLE, AccessorStyle::GETTERS],
        ];
    }

    public function testRejectsPublicPropertiesForImmutableDefaultsWithoutReadonly(): void
    {
        $this->expectException(IncompatibleTarget::class);
        $this->expectExceptionMessage('Immutable DTOs with public properties requires readonly-properties (PHP 8.1+), but the target is PHP 8.0.');

        $this->profile('8.0', MetadataMode::NONE, Mutability::IMMUTABLE, AccessorStyle::PUBLIC_PROPERTIES);
    }

    public function testRejectsPublicPropertiesForAnImmutableOverrideWithoutReadonly(): void
    {
        $profile = $this->profile('7.4', MetadataMode::NONE, Mutability::MUTABLE, AccessorStyle::PUBLIC_PROPERTIES);

        self::assertSame(AccessorStyle::from(AccessorStyle::PUBLIC_PROPERTIES), $profile->accessorsFor(Mutability::from(Mutability::MUTABLE)));

        $this->expectException(IncompatibleTarget::class);
        $profile->accessorsFor(Mutability::from(Mutability::IMMUTABLE));
    }

    public function testExplicitGettersAreKeptAsConfigured(): void
    {
        $profile = $this->profile('8.4', MetadataMode::NONE, Mutability::IMMUTABLE, AccessorStyle::GETTERS);

        self::assertSame(AccessorStyle::from(AccessorStyle::GETTERS), $profile->accessorsFor(Mutability::from(Mutability::IMMUTABLE)));
    }

    public function testExposesItsSettings(): void
    {
        $profile = new TargetProfile(
            PhpVersion::fromString('8.2'),
            MetadataMode::from(MetadataMode::ATTRIBUTES),
            Mutability::from(Mutability::MUTABLE),
            AccessorStyle::from(AccessorStyle::AUTO),
            DateTimeClass::from(DateTimeClass::MUTABLE),
            false,
        );

        self::assertSame('8.2', $profile->php()->toString());
        self::assertSame(MetadataMode::from(MetadataMode::ATTRIBUTES), $profile->metadata());
        self::assertSame(Mutability::from(Mutability::MUTABLE), $profile->mutability());
        self::assertSame(AccessorStyle::from(AccessorStyle::AUTO), $profile->accessors());
        self::assertSame(DateTimeClass::from(DateTimeClass::MUTABLE), $profile->dateTimeClass());
        self::assertFalse($profile->isStrict());
    }

    private function profile(
        string $php,
        string $metadata = MetadataMode::NONE,
        string $mutability = Mutability::IMMUTABLE,
        string $accessors = AccessorStyle::AUTO
    ): TargetProfile {
        return new TargetProfile(
            PhpVersion::fromString($php),
            MetadataMode::from($metadata),
            Mutability::from($mutability),
            AccessorStyle::from($accessors),
            DateTimeClass::from(DateTimeClass::IMMUTABLE),
            true,
        );
    }
}
