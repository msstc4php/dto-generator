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

        $required = $composition->withInheritedRequirements(['name' => $name]);

        self::assertSame([], $composition->inheritedRequirements());
        self::assertSame(['name' => $name], $required->inheritedRequirements());
    }
}
