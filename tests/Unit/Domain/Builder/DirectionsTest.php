<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Builder\Direction;
use MSSTC4PHP\DtoGenerator\Domain\Builder\Directions;
use MSSTC4PHP\DtoGenerator\Domain\Builder\PropertyView;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostic;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaGraph;
use MSSTC4PHP\DtoGenerator\Tests\Support\GraphFixture;
use PHPUnit\Framework\TestCase;

final class DirectionsTest extends TestCase
{
    public function testReadsTheDirectionOfAProperty(): void
    {
        self::assertSame('read', $this->direction(['type' => 'integer', 'readOnly' => true]));
        self::assertSame('write', $this->direction(['type' => 'string', 'writeOnly' => true]));
        self::assertNull($this->direction(['type' => 'string']));
        self::assertNull($this->direction(['type' => 'string', 'readOnly' => false, 'writeOnly' => false]));
    }

    public function testFollowsTheReferencesOfAProperty(): void
    {
        self::assertSame('read', $this->direction(['$ref' => '#/components/schemas/Audit']));
        self::assertSame('write', $this->direction(['$ref' => '#/components/schemas/Secret']));
        self::assertNull($this->direction(['$ref' => '#/components/schemas/Secret', 'writeOnly' => false, 'readOnly' => true], ['warning /project/api/openapi.yaml#/components/schemas/Holder/properties/value: "readOnly" and "writeOnly" are both true; the property is in both views.']));
        self::assertNull($this->direction(['$ref' => '#/components/schemas/Loop']));
    }

    public function testTakesAPropertyMarkedBothWaysForBoth(): void
    {
        self::assertNull($this->direction(
            ['type' => 'string', 'readOnly' => true, 'writeOnly' => true],
            ['warning /project/api/openapi.yaml#/components/schemas/Holder/properties/value: "readOnly" and "writeOnly" are both true; the property is in both views.'],
        ));
    }

    public function testIgnoresAFlagThatIsNoBoolean(): void
    {
        self::assertNull($this->direction(
            ['type' => 'string', 'readOnly' => 'yes'],
            ['warning /project/api/openapi.yaml#/components/schemas/Holder/properties/value/readOnly: "readOnly" must be true or false; it is ignored.'],
        ));
    }

    public function testAdmitsThePropertiesOfAView(): void
    {
        $graph = $this->graph(['type' => 'string']);
        $read = new PropertyView(Direction::from(Direction::READ), $graph);
        $write = new PropertyView(Direction::from(Direction::WRITE), $graph);
        $holder = $graph->all()[0]->schema();
        $id = $holder->requireProperty('id');
        $password = $holder->requireProperty('password');
        $name = $holder->requireProperty('value');
        $diagnostics = new Diagnostics();

        self::assertTrue($read->admits($id, $diagnostics));
        self::assertFalse($write->admits($id, $diagnostics));
        self::assertFalse($read->admits($password, $diagnostics));
        self::assertTrue($write->admits($password, $diagnostics));
        self::assertTrue($read->admits($name, $diagnostics));
        self::assertTrue($write->admits($name, $diagnostics));
        self::assertTrue($read->direction()->equals(Direction::from(Direction::READ)));
    }

    /**
     * @param array<string, mixed> $property
     * @param list<string> $messages
     */
    private function direction(array $property, array $messages = []): ?string
    {
        $graph = $this->graph($property);
        $diagnostics = new Diagnostics();
        $direction = Directions::of($graph->all()[0]->schema()->requireProperty('value'), $graph, $diagnostics);

        self::assertSame($messages, array_map(static fn (Diagnostic $diagnostic): string => $diagnostic->toString(), $diagnostics->all()));

        return $direction instanceof Direction ? $direction->value() : null;
    }

    /**
     * @param array<string, mixed> $property
     */
    private function graph(array $property): SchemaGraph
    {
        return GraphFixture::load([
            'Holder' => ['type' => 'object', 'properties' => [
                'value' => $property,
                'id' => ['type' => 'integer', 'readOnly' => true],
                'password' => ['type' => 'string', 'writeOnly' => true],
            ]],
            'Audit' => ['type' => 'object', 'readOnly' => true, 'properties' => ['by' => ['type' => 'string']]],
            'Secret' => ['$ref' => '#/components/schemas/Hidden'],
            'Hidden' => ['type' => 'string', 'writeOnly' => true],
            'Loop' => ['$ref' => '#/components/schemas/Loop'],
        ]);
    }
}
