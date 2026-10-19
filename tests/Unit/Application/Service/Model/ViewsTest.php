<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Application\Service\Model;

use Closure;
use MSSTC4PHP\DtoGenerator\Application\Config\ViewSuffixes;
use MSSTC4PHP\DtoGenerator\Application\Service\Model\Build\BuiltClass;
use MSSTC4PHP\DtoGenerator\Application\Service\Model\Build\Output;
use MSSTC4PHP\DtoGenerator\Application\Service\Model\Build\Run;
use MSSTC4PHP\DtoGenerator\Application\Service\Model\Build\View;
use MSSTC4PHP\DtoGenerator\Application\Service\Model\Build\Views;
use MSSTC4PHP\DtoGenerator\Domain\Builder\ReadWriteMode;
use MSSTC4PHP\DtoGenerator\Domain\Model\AttributeModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaGraph;
use MSSTC4PHP\DtoGenerator\Domain\Target\ReadWriteModels;
use MSSTC4PHP\DtoGenerator\Tests\Support\ModelFixture;
use PHPUnit\Framework\TestCase;

final class ViewsTest extends TestCase
{
    public function testReportsASharedClassThatTheTwoBuildsDisagreeOn(): void
    {
        $colour = ['type' => 'string', 'enum' => ['red']];
        $read = ModelFixture::build(['Tag' => ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]], 'Colour' => $colour]);
        $write = ModelFixture::build(['Tag' => ['type' => 'object', 'properties' => ['b' => ['type' => 'string']]], 'Colour' => $colour]);

        $merged = $this->views()->merge($read, $write);

        self::assertSame(['App\Dto\Tag' => ['a: string|null']], ModelFixture::classes($merged));
        self::assertSame(['App\Dto\Colour'], ModelFixture::enums($merged));
        self::assertSame(
            ['error /project/api/openapi.yaml#/components/schemas/Tag: App\Dto\Tag comes out differently in the read and the write build, which is a defect of the generator; please report it.'],
            ModelFixture::messages($merged),
        );
    }

    /**
     * @return array<string, array{array<string, array<array-key, mixed>>, array<string, array<array-key, mixed>>}>
     */
    public static function disagreements(): array
    {
        $tag = ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]];
        $base = ['type' => 'object', 'required' => ['kind'], 'properties' => ['kind' => ['type' => 'string']]];
        $variant = ['allOf' => [['$ref' => '#/components/schemas/Tag'], ['type' => 'object', 'properties' => ['v' => ['type' => 'string']]]]];
        $root = ['allOf' => [['$ref' => '#/components/schemas/Root'], $tag]];
        $sub = ['allOf' => [['$ref' => '#/components/schemas/Tag'], ['type' => 'object', 'properties' => ['b' => ['type' => 'string']]]]];

        return [
            'type' => [['Tag' => $tag], ['Tag' => ['type' => 'object', 'properties' => ['a' => ['type' => 'integer']]]]],
            'kind and type' => [['Tag' => $tag], ['Tag' => ['type' => 'object', 'properties' => ['a' => ['type' => 'integer']]], 'Sub' => ['allOf' => [['$ref' => '#/components/schemas/Tag'], ['type' => 'object', 'properties' => ['b' => ['type' => 'string']]]]]]],
            'parent' => [['Tag' => $tag, 'Top' => ['type' => 'object']], ['Tag' => ['allOf' => [['$ref' => '#/components/schemas/Top'], $tag]], 'Top' => ['type' => 'object', 'properties' => ['t' => ['type' => 'string']]]]],
            'variants' => [
                ['Tag' => $base + ['discriminator' => ['propertyName' => 'kind']], 'A' => $variant, 'B' => $variant],
                ['Tag' => $base + ['discriminator' => ['propertyName' => 'kind', 'mapping' => ['x' => '#/components/schemas/A', 'y' => '#/components/schemas/B']]], 'A' => $variant, 'B' => $variant],
            ],
            'kind and the values it is selected by' => [
                ['Root' => $base + ['discriminator' => ['propertyName' => 'kind', 'mapping' => ['t' => '#/components/schemas/Tag']]], 'Tag' => $root],
                ['Root' => $base + ['discriminator' => ['propertyName' => 'kind', 'mapping' => ['t' => '#/components/schemas/Tag', 's' => '#/components/schemas/Sub']]], 'Tag' => $root, 'Sub' => $sub],
            ],
        ];
    }

    /**
     * @dataProvider disagreements
     *
     * @param array<string, array<array-key, mixed>> $read
     * @param array<string, array<array-key, mixed>> $write
     */
    public function testReportsEveryKindOfDisagreement(array $read, array $write): void
    {
        $merged = $this->views()->merge(ModelFixture::build($read), ModelFixture::build($write));

        self::assertContains(
            'error /project/api/openapi.yaml#/components/schemas/Tag: App\Dto\Tag comes out differently in the read and the write build, which is a defect of the generator; please report it.',
            ModelFixture::messages($merged),
        );
    }

    /**
     * @dataProvider extendedInOneBuild
     *
     * @param array<string, array<array-key, mixed>> $read
     * @param array<string, array<array-key, mixed>> $write
     */
    public function testKeepsTheOpenClassThatOnlyOneBuildExtends(array $read, array $write): void
    {
        $merged = $this->views()->merge(ModelFixture::build($read), ModelFixture::build($write));

        self::assertSame([], ModelFixture::messages($merged));
        self::assertSame('open', ModelFixture::hierarchy($merged)['App\\Dto\\Tag']);
        self::assertSame('final extends App\\Dto\\Tag', ModelFixture::hierarchy($merged)['App\\Dto\\Sub']);
    }

    /**
     * @dataProvider leftOutOfTheShape
     *
     * @param Closure(ClassModel): ClassModel $change
     */
    public function testReportsAnOpenClassThatDiffersInWhatItsShapeLeavesOut(Closure $change): void
    {
        $tag = ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]];
        $sub = ['allOf' => [['$ref' => '#/components/schemas/Tag'], ['type' => 'object', 'properties' => ['b' => ['type' => 'string']]]]];
        $write = ModelFixture::build(['Tag' => $tag, 'Sub' => $sub]);
        $changed = new Output(
            array_map(static fn (BuiltClass $class): BuiltClass => $class->model()->name()->shortName() === 'Tag' ? new BuiltClass($change($class->model()), $class->source()) : $class, $write->classes()),
            $write->diagnostics(),
            $write->enums(),
        );

        $merged = $this->views()->merge(ModelFixture::build(['Tag' => $tag]), $changed);

        self::assertContains(
            'error /project/api/openapi.yaml#/components/schemas/Tag: App\Dto\Tag comes out differently in the read and the write build, which is a defect of the generator; please report it.',
            ModelFixture::messages($merged),
        );
    }

    /**
     * @return iterable<string, array{Closure(ClassModel): ClassModel}>
     */
    public static function leftOutOfTheShape(): iterable
    {
        yield 'discriminated properties' => [static fn (ClassModel $model): ClassModel => $model->withDiscriminatedProperties('a')];
        yield 'restored mutators' => [static fn (ClassModel $model): ClassModel => $model->withRestoredMutators('a')];
        yield 'attributes' => [static fn (ClassModel $model): ClassModel => $model->withAddedAttributes(new AttributeModel(ClassName::fromFqcn('App\\Marker')))];
    }

    /**
     * @return iterable<string, array{array<string, array<array-key, mixed>>, array<string, array<array-key, mixed>>}>
     */
    public static function extendedInOneBuild(): iterable
    {
        $tag = ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]];
        $sub = ['allOf' => [['$ref' => '#/components/schemas/Tag'], ['type' => 'object', 'properties' => ['b' => ['type' => 'string']]]]];

        yield 'by the write build' => [['Tag' => $tag], ['Tag' => $tag, 'Sub' => $sub]];
        yield 'by the read build' => [['Tag' => $tag, 'Sub' => $sub], ['Tag' => $tag]];
    }

    public function testBuildsOnceWithoutDependentClasses(): void
    {
        $single = ModelFixture::build(['Tag' => ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]], 'Old' => ['type' => 'object', 'properties' => ['a' => ['type' => 'string']], 'oneOf' => [['required' => ['a']], ['type' => 'object']]]]);
        $builds = 0;

        $output = $this->views()->build(static function (?View $view) use ($single, &$builds): Run {
            $builds++;

            return new Run($single, []);
        });

        self::assertSame(1, $builds);
        self::assertSame(ModelFixture::classes($single), ModelFixture::classes($output));
        self::assertSame(ModelFixture::messages($single), ModelFixture::messages($output));
        self::assertNotSame([], ModelFixture::messages($output));
    }

    public function testTakesAnEnumOfEitherBuild(): void
    {
        $merged = $this->views()->merge(
            ModelFixture::build(['Red' => ['type' => 'string', 'enum' => ['red']]]),
            ModelFixture::build(['Red' => ['type' => 'string', 'enum' => ['red']], 'Blue' => ['type' => 'string', 'enum' => ['blue']]]),
        );

        self::assertSame(['App\Dto\Red', 'App\Dto\Blue'], ModelFixture::enums($merged));
    }

    public function testNamesAProblemOfAWriteViewAfterItsReadViewByWholeNames(): void
    {
        $conflict = static fn (string $class): array => ['x-php-class-name' => $class, 'allOf' => [
            ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]],
            ['type' => 'object', 'properties' => ['a' => ['type' => 'integer']]],
        ]];
        $read = ModelFixture::build(['Pet' => $conflict('PetRead')]);
        $write = ModelFixture::build(['Pet' => $conflict('PetWrite'), 'Log' => $conflict('PetWriteLog')]);

        $merged = $this->views()->merge($read, $write);

        self::assertSame([
            'error /project/api/openapi.yaml#/components/schemas/Pet/allOf/1/properties/a: Property "a" of App\Dto\PetRead is int|null here, but string|null in an earlier allOf member.',
            'error /project/api/openapi.yaml#/components/schemas/Log/allOf/1/properties/a: Property "a" of App\Dto\PetWriteLog is int|null here, but string|null in an earlier allOf member.',
        ], ModelFixture::messages($merged));
    }

    public function testKeepsASharedClassBothBuildsAgreeOn(): void
    {
        $tag = [
            'Tag' => ['type' => 'object', 'required' => ['kind'], 'properties' => ['kind' => ['type' => 'string']], 'discriminator' => ['propertyName' => 'kind']],
            'A' => ['allOf' => [['$ref' => '#/components/schemas/Tag'], ['type' => 'object', 'properties' => ['v' => ['type' => 'string']]]]],
        ];

        $merged = $this->views()->merge(ModelFixture::build($tag), ModelFixture::build($tag));

        self::assertSame(['App\Dto\Tag', 'App\Dto\A'], array_keys(ModelFixture::classes($merged)));
        self::assertSame([], ModelFixture::messages($merged));
    }

    private function views(): Views
    {
        return new Views(new SchemaGraph([]), new ViewSuffixes(), new ReadWriteMode(ReadWriteModels::from(ReadWriteModels::SPLIT)));
    }
}
