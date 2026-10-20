<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Application\Service\Model\Build;

use MSSTC4PHP\DtoGenerator\Application\Service\Model\Build\Registry;
use MSSTC4PHP\DtoGenerator\Domain\Builder\SchemaParser;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Model\DocModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\EnumBacking;
use MSSTC4PHP\DtoGenerator\Domain\Model\EnumCase;
use MSSTC4PHP\DtoGenerator\Domain\Model\EnumModel;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use PHPUnit\Framework\TestCase;

final class RegistryTest extends TestCase
{
    private const POINT = ['type' => 'object', 'properties' => ['x' => []]];

    public function testGivesARepeatedSchemaTheClassOfTheOneHoldingTheName(): void
    {
        $registry = new Registry();
        $name = ClassName::fromFqcn('App\Point');
        $holder = $this->schema(self::POINT, 'a.yaml', '/A');
        self::assertTrue($registry->claim($name, $holder, new Diagnostics()));
        $registry->planClass($holder, $name, 0);

        self::assertTrue($registry->reuse(ClassName::fromFqcn('App\point'), $this->schema(self::POINT, 'a.yaml', '/B')));
        self::assertFalse($registry->reuse($name, $this->schema(self::POINT, 'b.yaml', '/A')));
        self::assertFalse($registry->reuse($name, $this->schema(['properties' => ['y' => []]] + self::POINT, 'a.yaml', '/C')));
        self::assertFalse($registry->reuse(ClassName::fromFqcn('App\Other'), $this->schema(self::POINT, 'a.yaml', '/D')));
        self::assertSame($name, $registry->declarations()->classAt('a.yaml#/B'));
        self::assertNull($registry->declarations()->classAt('b.yaml#/A'));
        self::assertNull($registry->declarations()->classAt('a.yaml#/C'));
        self::assertNull($registry->declarations()->enumAt('a.yaml#/B'));
    }

    public function testGivesARepeatedSchemaTheEnumOfTheOneHoldingTheName(): void
    {
        $registry = new Registry();
        $name = ClassName::fromFqcn('App\Level');
        $holder = $this->schema(['enum' => ['low']], 'a.yaml', '/A');
        self::assertTrue($registry->claim($name, $holder, new Diagnostics()));
        $registry->addEnum($holder, new EnumModel($name, EnumBacking::from(EnumBacking::STRING), [new EnumCase('LOW', 'low')], DocModel::none(), $holder->location()), 0);

        self::assertTrue($registry->reuse($name, $this->schema(['enum' => ['low']], 'a.yaml', '/B')));
        self::assertSame($registry->declarations()->enumAt('a.yaml#/A'), $registry->declarations()->enumAt('a.yaml#/B'));
        self::assertNull($registry->declarations()->classAt('a.yaml#/B'));
    }

    public function testLeavesASchemaAloneWhenTheOneHoldingTheNameIsNotDeclared(): void
    {
        $registry = new Registry();
        $name = ClassName::fromFqcn('App\Point');
        self::assertTrue($registry->claim($name, $this->schema(self::POINT, 'a.yaml', '/A'), new Diagnostics()));

        self::assertFalse($registry->reuse($name, $this->schema(self::POINT, 'a.yaml', '/B')));
        self::assertNull($registry->declarations()->classAt('a.yaml#/B'));
    }

    /**
     * @param array<array-key, mixed> $node
     */
    private function schema(array $node, string $file, string $pointer): Schema
    {
        return (new SchemaParser())->parse($node, new SchemaLocation($file, $pointer), new Diagnostics());
    }
}
