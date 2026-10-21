<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Application\Service\Model;

use MSSTC4PHP\DtoGenerator\Application\Service\Model\Build\Output;
use MSSTC4PHP\DtoGenerator\Domain\Shared\DefaultValue;
use MSSTC4PHP\DtoGenerator\Domain\Target\AllOfStrategy;
use MSSTC4PHP\DtoGenerator\Tests\Support\ModelFixture;
use PHPUnit\Framework\TestCase;

final class ObjectDefaultsBuildTest extends TestCase
{
    private const AT = 'warning /project/api/openapi.yaml#/components/schemas/';

    private const META = ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]];

    public function testKeepsAnEmptyObjectForAClassThatTakesNoArguments(): void
    {
        $output = ModelFixture::build([
            'Meta' => self::META,
            'C' => ['type' => 'object', 'properties' => ['meta' => ['$ref' => '#/components/schemas/Meta', 'default' => []]]],
        ]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(['meta' => '[]'], $this->defaults($output, 'C'));
    }

    public function testRefusesAClassThatIsNotGenerated(): void
    {
        $output = ModelFixture::build(['C' => ['type' => 'object', 'properties' => ['money' => ['x-php-type' => 'App\Money', 'default' => []]]]]);

        self::assertSame([self::AT . 'C/properties/money/default: A default for App\Money cannot be a PHP constant expression; null is used instead.'], ModelFixture::messages($output));
        self::assertSame(['money' => 'null'], $this->defaults($output, 'C'));
    }

    public function testRefusesAClassWithARequiredProperty(): void
    {
        $output = ModelFixture::build([
            'Meta' => ['type' => 'object', 'required' => ['full_name'], 'properties' => ['full_name' => ['type' => 'string']]],
            'C' => ['type' => 'object', 'properties' => ['meta' => ['$ref' => '#/components/schemas/Meta', 'default' => []]]],
        ]);

        self::assertSame([self::AT . 'C/properties/meta/default: The default {} cannot be `new App\Dto\Meta()`: {} lacks "full_name", which its constructor requires; null is used instead.'], ModelFixture::messages($output));
        self::assertSame(['meta' => 'null'], $this->defaults($output, 'C'));
    }

    public function testRefusesAClassThatInheritsARequiredProperty(): void
    {
        $output = ModelFixture::build([
            'Base' => ['type' => 'object', 'required' => ['id'], 'properties' => ['id' => ['type' => 'string']]],
            'Meta' => ['allOf' => [['$ref' => '#/components/schemas/Base'], self::META]],
            'C' => ['type' => 'object', 'properties' => ['meta' => ['$ref' => '#/components/schemas/Meta', 'default' => []]]],
        ]);

        self::assertSame([self::AT . 'C/properties/meta/default: The default {} cannot be `new App\Dto\Meta()`: {} lacks "id", which its constructor requires; null is used instead.'], ModelFixture::messages($output));
    }

    public function testRefusesAnAbstractClassButNotASubclassItsDiscriminatorSelects(): void
    {
        $output = ModelFixture::build([
            'Pet' => ['type' => 'object', 'required' => ['kind'], 'properties' => ['kind' => ['type' => 'string']], 'discriminator' => ['propertyName' => 'kind', 'mapping' => ['cat' => '#/components/schemas/Cat', 'dog' => '#/components/schemas/Dog']]],
            'Cat' => ['allOf' => [['$ref' => '#/components/schemas/Pet'], self::META]],
            'Dog' => ['allOf' => [['$ref' => '#/components/schemas/Pet'], self::META]],
            'C' => ['type' => 'object', 'properties' => [
                'pet' => ['$ref' => '#/components/schemas/Pet', 'default' => []],
                'cat' => ['$ref' => '#/components/schemas/Cat', 'default' => []],
            ]],
        ]);

        self::assertSame([self::AT . 'C/properties/pet/default: The default {} cannot be `new App\Dto\Pet()`: the class is abstract; null is used instead.'], ModelFixture::messages($output));
        self::assertSame(['pet' => 'null', 'cat' => '[]'], $this->defaults($output, 'C'));
    }

    /**
     * @dataProvider selections
     *
     * @param array<string, mixed> $pet
     * @param list<string> $messages
     */
    public function testAsksADiscriminatorOfSeveralValuesForADefaultThatSelectsTheClass(array $pet, array $messages): void
    {
        $output = ModelFixture::build([
            'Pet' => $pet + ['type' => 'object', 'discriminator' => ['propertyName' => 'kind', 'mapping' => ['cat' => '#/components/schemas/Cat', 'kitty' => '#/components/schemas/Cat', 'dog' => '#/components/schemas/Dog']]],
            'Cat' => ['allOf' => [['$ref' => '#/components/schemas/Pet'], self::META]],
            'Dog' => ['allOf' => [['$ref' => '#/components/schemas/Pet'], self::META]],
            'C' => ['type' => 'object', 'properties' => ['cat' => ['$ref' => '#/components/schemas/Cat', 'default' => []]]],
        ]);

        self::assertSame($messages, ModelFixture::messages($output));
    }

    /**
     * @return array<string, array{array<string, mixed>, list<string>}>
     */
    public static function selections(): array
    {
        $refused = [self::AT . 'C/properties/cat/default: The default {} cannot be `new App\Dto\Cat()`: {} lacks "kind", which its constructor requires; null is used instead.'];

        return [
            'required' => [['required' => ['kind'], 'properties' => ['kind' => ['type' => 'string']]], $refused],
            'a default that selects it' => [['properties' => ['kind' => ['type' => 'string', 'default' => 'kitty']]], []],
            'a default that selects another class' => [['properties' => ['kind' => ['type' => 'string', 'default' => 'dog']]], $refused],
        ];
    }

    public function testReportsADefaultCopiedIntoAnotherClassOnce(): void
    {
        $output = ModelFixture::build([
            'Base' => ['type' => 'object', 'properties' => ['money' => ['x-php-type' => 'App\Money', 'default' => []]]],
            'Child' => ['allOf' => [['$ref' => '#/components/schemas/Base'], self::META]],
        ], [], ['*'], AllOfStrategy::MERGE);

        self::assertSame([self::AT . 'Base/properties/money/default: A default for App\Money cannot be a PHP constant expression; null is used instead.'], ModelFixture::messages($output));
        self::assertSame(['money' => 'null', 'name' => 'null'], $this->defaults($output, 'Child'));
    }

    public function testBreaksDefaultsThatLeadBackToTheirClass(): void
    {
        $output = ModelFixture::build([
            'A' => ['type' => 'object', 'properties' => ['b' => ['$ref' => '#/components/schemas/B', 'default' => []]]],
            'B' => ['type' => 'object', 'properties' => ['a' => ['$ref' => '#/components/schemas/A', 'default' => []], 'meta' => ['$ref' => '#/components/schemas/Meta', 'default' => []]]],
            'Meta' => self::META,
        ]);

        self::assertSame([self::AT . 'B/properties/a/default: The default {} cannot be `new App\Dto\A()`: the defaults of App\Dto\A lead back to it; null is used instead.'], ModelFixture::messages($output));
        self::assertSame(['b' => '[]'], $this->defaults($output, 'A'));
        self::assertSame(['a' => 'null', 'meta' => '[]'], $this->defaults($output, 'B'));
    }

    public function testBreaksALongerLoopOnce(): void
    {
        $output = ModelFixture::build([
            'A' => ['type' => 'object', 'properties' => ['b' => ['$ref' => '#/components/schemas/B', 'default' => []]]],
            'B' => ['type' => 'object', 'properties' => ['c' => ['$ref' => '#/components/schemas/C', 'default' => []]]],
            'C' => ['type' => 'object', 'properties' => ['a' => ['$ref' => '#/components/schemas/A', 'default' => []]]],
        ]);

        self::assertSame([self::AT . 'C/properties/a/default: The default {} cannot be `new App\Dto\A()`: the defaults of App\Dto\A lead back to it; null is used instead.'], ModelFixture::messages($output));
        self::assertSame(['b' => '[]'], $this->defaults($output, 'A'));
        self::assertSame(['c' => '[]'], $this->defaults($output, 'B'));
        self::assertSame(['a' => 'null'], $this->defaults($output, 'C'));
    }

    /**
     * Child copies "t" of Base, so `new Child()` and `new T()` create each other; the loop is broken at the edge the
     * walk from the first class meets last, which here is the copied property, refused in Base too.
     */
    public function testBreaksALoopThroughACopiedPropertyWhereTheWalkClosesIt(): void
    {
        $output = ModelFixture::build([
            'Base' => ['type' => 'object', 'properties' => ['t' => ['$ref' => '#/components/schemas/T', 'default' => []]]],
            'Child' => ['allOf' => [['$ref' => '#/components/schemas/Base'], self::META]],
            'T' => ['type' => 'object', 'properties' => ['c' => ['$ref' => '#/components/schemas/Child', 'default' => []]]],
        ], [], ['*'], AllOfStrategy::MERGE);

        self::assertSame([self::AT . 'Base/properties/t/default: The default {} cannot be `new App\Dto\T()`: the defaults of App\Dto\T lead back to it; null is used instead.'], ModelFixture::messages($output));
        self::assertSame(['t' => 'null'], $this->defaults($output, 'Base'));
        self::assertSame(['t' => 'null', 'name' => 'null'], $this->defaults($output, 'Child'));
        self::assertSame(['c' => '[]'], $this->defaults($output, 'T'));
    }

    public function testBreaksADefaultOfItsOwnClass(): void
    {
        $output = ModelFixture::build(['Node' => ['type' => 'object', 'properties' => ['next' => ['$ref' => '#/components/schemas/Node', 'default' => []]]]]);

        self::assertSame([self::AT . 'Node/properties/next/default: The default {} cannot be `new App\Dto\Node()`: the defaults of App\Dto\Node lead back to it; null is used instead.'], ModelFixture::messages($output));
    }

    public function testKeepsAClassWhoseNestedDefaultIsRefused(): void
    {
        $output = ModelFixture::build([
            'Outer' => ['type' => 'object', 'properties' => ['inner' => ['$ref' => '#/components/schemas/Inner', 'default' => []]]],
            'Inner' => ['type' => 'object', 'properties' => ['money' => ['x-php-type' => 'App\Money', 'default' => []]]],
        ]);

        self::assertSame([self::AT . 'Inner/properties/money/default: A default for App\Money cannot be a PHP constant expression; null is used instead.'], ModelFixture::messages($output));
        self::assertSame(['inner' => '[]'], $this->defaults($output, 'Outer'));
    }

    /**
     * @return array<string, string> wire name → the default as JSON
     */
    private function defaults(Output $output, string $class): array
    {
        $defaults = [];
        foreach ($output->classes() as $built) {
            if ($built->model()->name()->fqcn() !== 'App\Dto\\' . $class) {
                continue;
            }

            foreach ($built->model()->properties() as $property) {
                $default = $property->default();
                $defaults[$property->wireName()] = $default instanceof DefaultValue ? $default->toJson() : '-';
            }
        }

        return $defaults;
    }
}
