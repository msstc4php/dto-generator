<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Builder\Composition;
use MSSTC4PHP\DtoGenerator\Tests\Support\GraphFixture;
use PHPUnit\Framework\TestCase;

final class CompositionTest extends TestCase
{
    public function testAddsInheritedRequirementsToACopy(): void
    {
        $schema = GraphFixture::load(['Pet' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]]])->all()[0]->schema();
        $name = $schema->requireProperty('name');
        $composition = Composition::of($schema);

        $required = $composition->withInheritedRequirements(['name' => [$name]]);

        self::assertSame([], $composition->inheritedRequirements());
        self::assertSame(['name' => [$name]], $required->inheritedRequirements());
    }

    public function testRequiresMoreInACopy(): void
    {
        $schema = GraphFixture::load(['Pet' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]]])->all()[0]->schema();
        $composition = Composition::of($schema);

        $required = $composition->withRequired(['name' => 'name', 1 => '1']);

        self::assertFalse($composition->isRequired('name'));
        self::assertTrue($required->isRequired('name'));
        self::assertSame(['name' => 'name', '1' => '1'], $required->required());
    }
}
