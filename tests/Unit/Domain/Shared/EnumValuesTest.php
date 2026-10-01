<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Shared;

use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Severity;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassKind;
use MSSTC4PHP\DtoGenerator\Domain\Model\EnumBacking;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaType;
use MSSTC4PHP\DtoGenerator\Domain\Shared\AbstractEnum;
use MSSTC4PHP\DtoGenerator\Domain\Target\AccessorStyle;
use MSSTC4PHP\DtoGenerator\Domain\Target\DateTimeClass;
use MSSTC4PHP\DtoGenerator\Domain\Target\MetadataMode;
use MSSTC4PHP\DtoGenerator\Domain\Target\Mutability;
use PHPUnit\Framework\TestCase;

/**
 * The values are what users write in the config and schema; changing them is a breaking change.
 */
final class EnumValuesTest extends TestCase
{
    /**
     * @dataProvider valueSets
     *
     * @param class-string<AbstractEnum> $enum
     * @param list<string> $expected
     */
    public function testPublishesItsValueSet(string $enum, array $expected): void
    {
        self::assertSame($expected, array_map(static fn (AbstractEnum $case): string => $case->value(), $enum::cases()));
    }

    /**
     * @return array<string, array{class-string<AbstractEnum>, list<string>}>
     */
    public static function valueSets(): array
    {
        return [
            'accessors' => [AccessorStyle::class, ['auto', 'getters', 'public-properties']],
            'metadata' => [MetadataMode::class, ['attributes', 'annotations', 'none']],
            'mutability' => [Mutability::class, ['immutable', 'mutable']],
            'date-time class' => [DateTimeClass::class, ['DateTimeImmutable', 'DateTime']],
            'schema type' => [SchemaType::class, ['string', 'integer', 'number', 'boolean', 'array', 'object', 'null']],
            'class kind' => [ClassKind::class, ['final', 'open', 'abstract']],
            'enum backing' => [EnumBacking::class, ['string', 'int']],
            'severity' => [Severity::class, ['error', 'warning']],
        ];
    }
}
