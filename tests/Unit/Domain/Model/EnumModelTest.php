<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Model;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Model\DocModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\EnumBacking;
use MSSTC4PHP\DtoGenerator\Domain\Model\EnumCase;
use MSSTC4PHP\DtoGenerator\Domain\Model\EnumModel;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use PHPUnit\Framework\TestCase;

final class EnumModelTest extends TestCase
{
    public function testExposesItsParts(): void
    {
        $active = new EnumCase('Active', 'active', new DocModel('Can log in'));
        $enum = $this->enum(EnumBacking::STRING, [$active, new EnumCase('Blocked', 'blocked')]);

        self::assertSame('App\Status', $enum->name()->fqcn());
        self::assertSame(EnumBacking::from(EnumBacking::STRING), $enum->backing());
        self::assertCount(2, $enum->cases());
        self::assertSame('Active', $active->name());
        self::assertSame('active', $active->value());
        self::assertSame('Can log in', $active->doc()->description());
        self::assertTrue((new EnumCase('Blocked', 'blocked'))->doc()->isEmpty());
        self::assertSame('a.json#/components/schemas/Status', $enum->source()->toString());
        self::assertTrue($enum->doc()->isEmpty());
    }

    public function testAcceptsIntBackedCases(): void
    {
        $enum = $this->enum(EnumBacking::INT, [new EnumCase('One', 1), new EnumCase('Two', 2)]);

        self::assertSame(1, $enum->cases()[0]->value());
    }

    public function testRejectsANumericStringInAnIntEnum(): void
    {
        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage('does not match the int backing');

        $this->enum(EnumBacking::INT, [new EnumCase('One', '1')]);
    }

    public function testRejectsAnIntInAStringEnum(): void
    {
        $this->expectException(InvalidModel::class);

        $this->enum(EnumBacking::STRING, [new EnumCase('One', 1)]);
    }

    public function testRejectsDuplicateValues(): void
    {
        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage('repeats value "active"');

        $this->enum(EnumBacking::STRING, [new EnumCase('Active', 'active'), new EnumCase('Enabled', 'active')]);
    }

    public function testRejectsDuplicateNames(): void
    {
        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage('repeats case "Active"');

        $this->enum(EnumBacking::STRING, [new EnumCase('Active', 'a'), new EnumCase('Active', 'b')]);
    }

    public function testRejectsAnEnumWithoutCases(): void
    {
        $this->expectException(InvalidModel::class);

        $this->enum(EnumBacking::STRING, []);
    }

    /**
     * @dataProvider invalidCaseNames
     */
    public function testRejectsInvalidCaseNames(string $name): void
    {
        $this->expectException(InvalidModel::class);

        new EnumCase($name, 'x');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidCaseNames(): array
    {
        return [
            'class keyword' => ['class'],
            'class keyword uppercase' => ['CLASS'],
            'dash' => ['in-progress'],
            'leading digit' => ['1st'],
        ];
    }

    /**
     * @param list<EnumCase> $cases
     */
    private function enum(string $backing, array $cases): EnumModel
    {
        return new EnumModel(
            ClassName::fromFqcn('App\Status'),
            EnumBacking::from($backing),
            $cases,
            DocModel::none(),
            new SchemaLocation('a.json', '/components/schemas/Status'),
        );
    }
}
