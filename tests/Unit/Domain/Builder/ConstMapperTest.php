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
