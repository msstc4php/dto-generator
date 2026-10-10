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
        $read = new PropertyView(Direction::from(Direction::READ), new Directions($graph, new Diagnostics()));
        $write = new PropertyView(Direction::from(Direction::WRITE), new Directions($graph, new Diagnostics()));
        $holder = $graph->all()[0]->schema();
        $id = $holder->requireProperty('id');
        $password = $holder->requireProperty('password');
        $name = $holder->requireProperty('value');
        self::assertTrue($read->admits($id));
        self::assertFalse($write->admits($id));
        self::assertFalse($read->admits($password));
        self::assertTrue($write->admits($password));
        self::assertTrue($read->admits($name));
        self::assertTrue($write->admits($name));
        self::assertSame(['password' => 'password'], $read->excluded([['id', $id], ['password', $password], ['value', $name]]));
        self::assertSame(['id' => 'id'], $write->excluded([['id', $id], ['password', $password], ['value', $name]]));
        self::assertTrue($read->direction()->equals(Direction::from(Direction::READ)));
    }

    public function testCombinesTheDeclarationsOfAPropertyAcrossAComposition(): void
    {
        $graph = $this->graph(['type' => 'string']);
        $holder = $graph->all()[0]->schema();
        $plain = $holder->requireProperty('value');
        $id = $holder->requireProperty('id');
        $password = $holder->requireProperty('password');
        $diagnostics = new Diagnostics();
        $directed = (new Directions($graph, $diagnostics))->ofSources([['x', $plain], ['x', $id], ['y', $id], ['y', $password], ['z', $password], ['z', $plain]]);

        self::assertSame([['x', 'read'], ['z', 'write']], array_map(static fn (array $pair): array => [$pair[0], $pair[1]->value()], $directed));
        self::assertSame(['warning /project/api/openapi.yaml#/components/schemas/Holder/properties/id: "readOnly" and "writeOnly" are both true; the property is in both views.'], array_map(static fn (Diagnostic $diagnostic): string => $diagnostic->toString(), $diagnostics->all()));
    }

    /**
     * @param array<string, mixed> $property
     * @param list<string> $messages
     */
    private function direction(array $property, array $messages = []): ?string
    {
        $graph = $this->graph($property);
        $diagnostics = new Diagnostics();
        $directions = new Directions($graph, $diagnostics);
        $property = $graph->all()[0]->schema()->requireProperty('value');
        $direction = $directions->of($property);
        // Read once: asking again reports nothing new.
        $directions->of($property);

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
