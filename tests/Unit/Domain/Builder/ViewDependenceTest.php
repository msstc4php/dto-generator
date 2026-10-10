<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Application\Service\Model\Build\BuiltClass;
use MSSTC4PHP\DtoGenerator\Domain\Builder\ViewDependence;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassModel;
use MSSTC4PHP\DtoGenerator\Tests\Support\ModelFixture;
use PHPUnit\Framework\TestCase;

final class ViewDependenceTest extends TestCase
{
    public function testSpreadsAlongReferencesParentsAndVariants(): void
    {
        $output = ModelFixture::build([
            'Pet' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
            'Owner' => ['type' => 'object', 'properties' => ['pets' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Pet']]]],
            'Shelter' => ['type' => 'object', 'properties' => [
                'owners' => ['type' => 'object', 'additionalProperties' => ['$ref' => '#/components/schemas/Owner']],
            ]],
            'Maybe' => ['type' => 'object', 'properties' => ['owner' => ['oneOf' => [['$ref' => '#/components/schemas/Owner'], ['type' => 'null']]]]],
            'Either' => ['type' => 'object', 'properties' => ['who' => ['oneOf' => [['$ref' => '#/components/schemas/Owner'], ['type' => 'string']]]]],
            'Cat' => ['allOf' => [['$ref' => '#/components/schemas/Pet'], ['type' => 'object', 'properties' => ['lives' => ['type' => 'integer']]]]],
            'Animal' => [
                'oneOf' => [['$ref' => '#/components/schemas/Fish'], ['$ref' => '#/components/schemas/Bird']],
                'discriminator' => ['propertyName' => 'kind'],
            ],
            'Fish' => ['type' => 'object', 'required' => ['kind'], 'properties' => ['kind' => ['type' => 'string'], 'fins' => ['type' => 'integer']]],
            'Bird' => ['type' => 'object', 'required' => ['kind'], 'properties' => ['kind' => ['type' => 'string']]],
            'Zoo' => ['type' => 'object', 'properties' => ['star' => ['$ref' => '#/components/schemas/Animal']]],
            'Plain' => ['type' => 'object', 'properties' => ['label' => ['type' => 'string']]],
        ]);
        $classes = array_map(static fn (BuiltClass $built): ClassModel => $built->model(), $output->classes());

        $pet = ViewDependence::of($classes, ['App\Dto\Pet' => true]);
        $fish = ViewDependence::of($classes, ['App\Dto\Fish' => true]);

        self::assertSame(['App\Dto\Cat', 'App\Dto\Either', 'App\Dto\Maybe', 'App\Dto\Owner', 'App\Dto\Pet', 'App\Dto\Shelter'], $this->sorted($pet));
        self::assertSame(['App\Dto\Animal', 'App\Dto\Bird', 'App\Dto\Fish', 'App\Dto\Zoo'], $this->sorted($fish));
        self::assertSame([], ViewDependence::of($classes, []));
    }

    /**
     * @param array<string, true> $set
     *
     * @return list<string>
     */
    private function sorted(array $set): array
    {
        $names = array_keys($set);
        sort($names);

        return $names;
    }
}
