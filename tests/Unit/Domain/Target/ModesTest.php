<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Target;

use DateTime;
use DateTimeImmutable;
use MSSTC4PHP\DtoGenerator\Domain\Target\DateTimeClass;
use MSSTC4PHP\DtoGenerator\Domain\Target\MetadataMode;
use MSSTC4PHP\DtoGenerator\Domain\Target\Mutability;
use MSSTC4PHP\DtoGenerator\Domain\Target\PhpVersion;
use PHPUnit\Framework\TestCase;

final class ModesTest extends TestCase
{
    public function testMetadataDefaultsToAnnotationsBeforePhp80(): void
    {
        self::assertSame(MetadataMode::from(MetadataMode::ANNOTATIONS), MetadataMode::defaultFor(PhpVersion::fromString('7.4')));
    }

    public function testMetadataDefaultsToAttributesFromPhp80(): void
    {
        self::assertSame(MetadataMode::from(MetadataMode::ATTRIBUTES), MetadataMode::defaultFor(PhpVersion::fromString('8.0')));
        self::assertSame(MetadataMode::from(MetadataMode::ATTRIBUTES), MetadataMode::defaultFor(PhpVersion::fromString('8.5')));
    }

    public function testMutabilityKnowsWhetherItIsImmutable(): void
    {
        self::assertTrue(Mutability::from(Mutability::IMMUTABLE)->isImmutable());
        self::assertFalse(Mutability::from(Mutability::MUTABLE)->isImmutable());
    }

    public function testDateTimeClassMapsToTheRealClass(): void
    {
        self::assertSame(DateTimeImmutable::class, DateTimeClass::from(DateTimeClass::IMMUTABLE)->className());
        self::assertSame(DateTime::class, DateTimeClass::from(DateTimeClass::MUTABLE)->className());
    }
}
