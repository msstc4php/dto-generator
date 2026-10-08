<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Contract;

use MSSTC4PHP\DtoGenerator\Contract\ClassContext;
use MSSTC4PHP\DtoGenerator\Contract\InstalledPackages;
use MSSTC4PHP\DtoGenerator\Contract\PropertyContext;
use MSSTC4PHP\DtoGenerator\Contract\SchemaReferences;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Model\DiscriminatorModel;
use MSSTC4PHP\DtoGenerator\Domain\Target\Mutability;
use MSSTC4PHP\DtoGenerator\Tests\Support\EmitterFixture;
use MSSTC4PHP\DtoGenerator\Tests\Support\GraphFixture;
use PHPUnit\Framework\TestCase;

final class ContextTest extends TestCase
{
    public function testExposesWhatAnEnricherMayRead(): void
    {
        $tag = EmitterFixture::tag();
        $schema = GraphFixture::load(['Tag' => ['type' => 'object', 'properties' => ['label' => ['type' => 'string']]]])->all()[0]->schema();
        $target = EmitterFixture::target('8.2', Mutability::IMMUTABLE);
        $packages = new InstalledPackages(['a/b' => '1.0']);
        $diagnostics = new Diagnostics();

        $class = new ClassContext($tag, $schema, $target, $packages, $diagnostics);
        $property = new PropertyContext($tag->properties()[0], $tag, $schema->requireProperty('label'), $target, $packages, $diagnostics);

        self::assertSame([$tag, $schema, $target, $packages, $diagnostics], [$class->class(), $class->schema(), $class->target(), $class->packages(), $class->diagnostics()]);
        self::assertSame(
            [$tag->properties()[0], $tag, $schema->requireProperty('label'), $target, $packages, $diagnostics],
            [$property->property(), $property->owner(), $property->schema(), $property->target(), $property->packages(), $property->diagnostics()],
        );
    }

    public function testNamesTheDiscriminatorThatSelectsTheClass(): void
    {
        $tag = EmitterFixture::tag();
        $schema = GraphFixture::load(['Tag' => ['type' => 'object', 'properties' => ['label' => ['type' => 'string']]]])->all()[0]->schema();
        $target = EmitterFixture::target('8.2', Mutability::IMMUTABLE);
        $discriminator = new DiscriminatorModel('kind', ['tag' => $tag->name()]);

        self::assertNull((new ClassContext($tag, $schema, $target, new InstalledPackages(), new Diagnostics()))->selectingDiscriminator());
        self::assertSame($discriminator, (new ClassContext($tag, $schema, $target, new InstalledPackages(), new Diagnostics(), false, null, $discriminator))->selectingDiscriminator());
    }

    public function testLetsAnEnricherFollowReferences(): void
    {
        $tag = EmitterFixture::tag();
        $graph = GraphFixture::load(['Tag' => ['type' => 'object', 'properties' => ['label' => ['type' => 'string']]]]);
        $schema = $graph->all()[0]->schema();
        $target = EmitterFixture::target('8.2', Mutability::IMMUTABLE);
        $references = new SchemaReferences($graph);

        self::assertSame($references, (new ClassContext($tag, $schema, $target, new InstalledPackages(), new Diagnostics(), false, $references))->references());
        self::assertSame($references, (new PropertyContext($tag->properties()[0], $tag, $schema, $target, new InstalledPackages(), new Diagnostics(), $references))->references());
        self::assertSame($schema, (new ClassContext($tag, $schema, $target, new InstalledPackages(), new Diagnostics()))->references()->resolve($schema));
        self::assertSame($schema, (new PropertyContext($tag->properties()[0], $tag, $schema, $target, new InstalledPackages(), new Diagnostics()))->references()->resolve($schema));
    }
}
