<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Target;

use MSSTC4PHP\DtoGenerator\Domain\Target\Capability;
use PHPUnit\Framework\TestCase;

final class CapabilityTest extends TestCase
{
    /**
     * @dataProvider minimumVersions
     */
    public function testKnowsTheVersionThatIntroducedIt(string $capability, string $version): void
    {
        self::assertSame($version, Capability::from($capability)->minimumVersion()->toString());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function minimumVersions(): array
    {
        return [
            Capability::TYPED_PROPERTIES => [Capability::TYPED_PROPERTIES, '7.4'],
            Capability::CONSTRUCTOR_PROMOTION => [Capability::CONSTRUCTOR_PROMOTION, '8.0'],
            Capability::UNION_TYPES => [Capability::UNION_TYPES, '8.0'],
            Capability::ATTRIBUTES => [Capability::ATTRIBUTES, '8.0'],
            Capability::MIXED_TYPE => [Capability::MIXED_TYPE, '8.0'],
            Capability::READONLY_PROPERTIES => [Capability::READONLY_PROPERTIES, '8.1'],
            Capability::ENUMS => [Capability::ENUMS, '8.1'],
            Capability::NEW_IN_INITIALIZERS => [Capability::NEW_IN_INITIALIZERS, '8.1'],
            Capability::READONLY_CLASSES => [Capability::READONLY_CLASSES, '8.2'],
            Capability::STANDALONE_NULL_FALSE => [Capability::STANDALONE_NULL_FALSE, '8.2'],
            Capability::TYPED_CLASS_CONSTANTS => [Capability::TYPED_CLASS_CONSTANTS, '8.3'],
            Capability::ASYMMETRIC_VISIBILITY => [Capability::ASYMMETRIC_VISIBILITY, '8.4'],
            Capability::PROPERTY_HOOKS => [Capability::PROPERTY_HOOKS, '8.4'],
            Capability::CLONE_WITH => [Capability::CLONE_WITH, '8.5'],
        ];
    }

    public function testTheMatrixCoversEveryCapability(): void
    {
        self::assertCount(count(self::minimumVersions()), Capability::cases());
    }
}
