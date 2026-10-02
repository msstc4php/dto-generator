<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Schema;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaIndex;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use MSSTC4PHP\DtoGenerator\Tests\Support\GraphFixture;
use PHPUnit\Framework\TestCase;

final class SchemaIndexTest extends TestCase
{
    private const AT = '/components/schemas/';

    public function testFindsEverySubschemaOfTheGraphByLocation(): void
    {
        $index = SchemaIndex::of(GraphFixture::load([
            'User' => ['type' => 'object', 'properties' => [
                'tags' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['label' => ['type' => 'string']]]],
                'meta' => ['type' => 'object', 'additionalProperties' => ['type' => 'integer']],
                'pet' => ['oneOf' => [['type' => 'string'], ['type' => 'integer']], 'anyOf' => [['minimum' => 1]]],
            ]],
            'Child' => ['allOf' => [['$ref' => '#/components/schemas/User'], ['properties' => ['x' => ['type' => 'boolean']]]]],
        ]));

        foreach ([
            'User', 'User/properties/tags/items/properties/label', 'User/properties/meta/additionalProperties',
            'User/properties/pet/oneOf/1', 'User/properties/pet/anyOf/0', 'Child/allOf/1/properties/x',
        ] as $pointer) {
            self::assertInstanceOf(Schema::class, $index->get($this->location($pointer)), $pointer);
        }

        self::assertSame('boolean', $this->type($index->get($this->location('Child/allOf/1/properties/x'))));
        self::assertNull($index->get($this->location('Nowhere')));
    }

    private function location(string $pointer): SchemaLocation
    {
        return new SchemaLocation(GraphFixture::SPEC, self::AT . $pointer);
    }

    private function type(?Schema $schema): string
    {
        self::assertNotNull($schema);

        return $schema->nonNullTypes()[0]->value();
    }

    public function testRequiresASchemaToExist(): void
    {
        $index = SchemaIndex::of(GraphFixture::load(['User' => ['type' => 'object', 'properties' => ['id' => []]]]));

        self::assertSame('User', basename($index->require($this->location('User'))->location()->pointer()));

        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage('No schema at /project/api/openapi.yaml#/components/schemas/Nowhere.');

        $index->require($this->location('Nowhere'));
    }
}
