<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Builder\ConstMapper;
use MSSTC4PHP\DtoGenerator\Domain\Builder\SchemaParser;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassType;
use MSSTC4PHP\DtoGenerator\Domain\Model\MixedType;
use MSSTC4PHP\DtoGenerator\Domain\Model\NullableType;
use MSSTC4PHP\DtoGenerator\Domain\Model\ScalarType;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use PHPUnit\Framework\TestCase;

final class ConstMapperTest extends TestCase
{
    public function testGivesTheLiteralOfTheConstant(): void
    {
        $mapper = new ConstMapper([]);

        self::assertSame("'a'", $this->describe($mapper->type($this->schema(['const' => 'a']), new Diagnostics())));
        self::assertSame('5', $this->describe($mapper->type($this->schema(['type' => 'integer', 'const' => 5.0]), new Diagnostics())));
        self::assertNull($mapper->type($this->schema(['const' => null]), new Diagnostics()));
    }

    public function testCastsOnlyWholeFloatsWithinTheRangeOfInt(): void
    {
        $mapper = new ConstMapper([]);
        $diagnostics = new Diagnostics();

        // -2^63 is PHP_INT_MIN; 2^63 is one past PHP_INT_MAX, and PHP 8.5 warns about casting it.
        self::assertSame((string) PHP_INT_MIN, $this->describe($mapper->type($this->schema(['type' => 'integer', 'const' => -2.0 ** 63]), $diagnostics)));
        self::assertSame([], $diagnostics->all());
        foreach ([2.0 ** 63, -2.0 ** 64] as $beyond) {
            $diagnostics = new Diagnostics();
            self::assertNull($mapper->type($this->schema(['type' => 'integer', 'const' => $beyond]), $diagnostics));
            self::assertCount(1, $diagnostics->all());
        }

        self::assertSame('float', $this->describe($mapper->type($this->schema(['const' => 2.0 ** 63]), new Diagnostics())));
    }

    public function testLeavesAConfiguredFormatItsClass(): void
    {
        $mapper = new ConstMapper(['money' => new ClassType(ClassName::fromFqcn('App\Money'))]);

        self::assertNull($mapper->type($this->schema(['type' => 'string', 'format' => 'money', 'const' => '1 EUR']), new Diagnostics()));
    }

    public function testNarrowsOnlyByBareConstMembers(): void
    {
        $mapper = new ConstMapper([]);
        $diagnostics = new Diagnostics();
        $schema = $this->schema(['allOf' => [['type' => 'string', 'const' => 'typed'], ['const' => 'x']]]);

        self::assertSame("'x'|null", $mapper->narrow($schema, new NullableType(ScalarType::string()), $diagnostics)->describe());
        self::assertSame("'x'", $mapper->narrow($schema, new MixedType(), $diagnostics)->describe());
        self::assertSame('App\Money', $mapper->narrow($schema, new ClassType(ClassName::fromFqcn('App\Money')), $diagnostics)->describe());
        self::assertSame([], $diagnostics->all());
    }

    /**
     * @param array<string, mixed> $node
     */
    private function schema(array $node): Schema
    {
        return (new SchemaParser())->parse($node, new SchemaLocation('s.yaml', '/S'), new Diagnostics());
    }

    private function describe(?ScalarType $type): ?string
    {
        return $type instanceof ScalarType ? $type->describe() : null;
    }
}
