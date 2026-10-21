<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Builder\ObjectDefaults;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostic;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassKind;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassType;
use MSSTC4PHP\DtoGenerator\Domain\Model\NullableType;
use MSSTC4PHP\DtoGenerator\Domain\Model\ScalarType;
use MSSTC4PHP\DtoGenerator\Domain\Shared\DefaultValue;
use MSSTC4PHP\DtoGenerator\Tests\Support\EmitterFixture;
use PHPUnit\Framework\TestCase;

final class ObjectDefaultsTest extends TestCase
{
    private const AT = 'warning /project/api/openapi.yaml#/components/schemas/X/properties/';

    public function testRefusesAnInterface(): void
    {
        $diagnostics = new Diagnostics();
        $classes = ObjectDefaults::resolve([
            EmitterFixture::model('App\Owner', null, [EmitterFixture::property('named', new NullableType($this->type('App\Named')), false, new DefaultValue([]))]),
            EmitterFixture::model('App\Named', null, [])->withHierarchy(ClassKind::from(ClassKind::INTERFACE), null, null),
        ], [], $diagnostics);

        self::assertEquals(new DefaultValue(null), $classes[0]->properties()[0]->default());
        self::assertSame([self::AT . 'named/default: The default {} cannot be `new App\Named()`: the class is abstract; null is used instead.'], $this->messages($diagnostics));
    }

    public function testLetsANarrowedPropertyHoldTheNullItFallsBackTo(): void
    {
        $diagnostics = new Diagnostics();
        [$owner] = ObjectDefaults::resolve([
            EmitterFixture::model('App\Owner', null, [EmitterFixture::property('money', $this->type('App\Money'), false, new DefaultValue([]))]),
        ], [], $diagnostics);

        $property = $owner->properties()[0];
        self::assertEquals(new NullableType($this->type('App\Money')), $property->type());
        self::assertFalse($property->isRequired());
        self::assertEquals(new DefaultValue(null), $property->default());
    }

    public function testCountsAnInheritedPropertyWithoutADefault(): void
    {
        $diagnostics = new Diagnostics();
        ObjectDefaults::resolve([
            EmitterFixture::model('App\Owner', null, [EmitterFixture::property('meta', new NullableType($this->type('App\Meta')), false, new DefaultValue([]))]),
            EmitterFixture::model('App\Meta', null, [EmitterFixture::property('label', ScalarType::string(), false, new DefaultValue('x'))]),
        ], ['App\Meta' => [EmitterFixture::property('id', ScalarType::string(), true)]], $diagnostics);

        self::assertSame([self::AT . 'meta/default: The default {} cannot be `new App\Meta()`: its constructor requires $id; null is used instead.'], $this->messages($diagnostics));
    }

    private function type(string $fqcn): ClassType
    {
        return new ClassType(ClassName::fromFqcn($fqcn));
    }

    /**
     * @return list<string>
     */
    private function messages(Diagnostics $diagnostics): array
    {
        return array_map(static fn (Diagnostic $diagnostic): string => $diagnostic->toString(), $diagnostics->all());
    }
}
