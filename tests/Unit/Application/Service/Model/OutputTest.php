<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Application\Service\Model;

use MSSTC4PHP\DtoGenerator\Application\Service\Model\Build\BuiltClass;
use MSSTC4PHP\DtoGenerator\Application\Service\Model\Build\Output;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassKind;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Model\PropertyModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ScalarType;
use MSSTC4PHP\DtoGenerator\Tests\Support\EmitterFixture;
use PHPUnit\Framework\TestCase;

final class OutputTest extends TestCase
{
    public function testCollectsInheritedPropertiesRootFirst(): void
    {
        $output = $this->output([
            $this->model('App\A', 'a', 'App\B'),
            $this->model('App\B', 'b', 'App\C'),
            $this->model('App\C', 'c', null),
        ]);

        self::assertSame(['c', 'b'], $this->names($output->inheritedProperties($output->classes()[0]->model())));
        self::assertSame([], $this->names($output->inheritedProperties($output->classes()[2]->model())));
    }

    public function testStopsWhereAnInheritanceLoopCloses(): void
    {
        $output = $this->output([
            $this->model('App\A', 'a', 'App\B'),
            $this->model('App\B', 'b', 'App\C'),
            $this->model('App\C', 'c', 'App\B'),
        ]);

        self::assertSame(['c', 'b'], $this->names($output->inheritedProperties($output->classes()[0]->model())));
        self::assertSame(['c'], $this->names($output->inheritedProperties($output->classes()[1]->model())));
    }

    public function testStopsAtAParentOutsideTheBuild(): void
    {
        $output = $this->output([$this->model('App\A', 'a', 'Vendor\Base')]);

        self::assertSame([], $output->inheritedProperties($output->classes()[0]->model()));
    }

    /**
     * @param list<ClassModel> $models
     */
    private function output(array $models): Output
    {
        return new Output(array_map(static fn (ClassModel $model): BuiltClass => new BuiltClass($model, 0), $models), new Diagnostics(), []);
    }

    private function model(string $fqcn, string $property, ?string $parent): ClassModel
    {
        return EmitterFixture::model($fqcn, null, [EmitterFixture::property($property, ScalarType::string(), true)])
            ->withHierarchy(ClassKind::from(ClassKind::OPEN), $parent === null ? null : ClassName::fromFqcn($parent), null)
        ;
    }

    /**
     * @param list<PropertyModel> $properties
     *
     * @return list<string>
     */
    private function names(array $properties): array
    {
        return array_map(static fn (PropertyModel $property): string => $property->name(), $properties);
    }
}
