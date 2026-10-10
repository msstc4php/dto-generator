<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Application\Service\Model;

use MSSTC4PHP\DtoGenerator\Application\Config\ViewSuffixes;
use MSSTC4PHP\DtoGenerator\Application\Service\Model\Build\Run;
use MSSTC4PHP\DtoGenerator\Application\Service\Model\Build\View;
use MSSTC4PHP\DtoGenerator\Application\Service\Model\Build\Views;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaGraph;
use MSSTC4PHP\DtoGenerator\Tests\Support\ModelFixture;
use PHPUnit\Framework\TestCase;

final class ViewsTest extends TestCase
{
    public function testReportsASharedClassThatTheTwoBuildsDisagreeOn(): void
    {
        $colour = ['type' => 'string', 'enum' => ['red']];
        $read = ModelFixture::build(['Tag' => ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]], 'Colour' => $colour]);
        $write = ModelFixture::build(['Tag' => ['type' => 'object', 'properties' => ['b' => ['type' => 'string']]], 'Colour' => $colour]);

        $merged = (new Views(new SchemaGraph([]), new ViewSuffixes()))->merge($read, $write);

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

        return [
            'type' => [['Tag' => $tag], ['Tag' => ['type' => 'object', 'properties' => ['a' => ['type' => 'integer']]]]],
            'kind' => [['Tag' => $tag], ['Tag' => $tag, 'Sub' => ['allOf' => [['$ref' => '#/components/schemas/Tag'], ['type' => 'object', 'properties' => ['b' => ['type' => 'string']]]]]]],
            'parent' => [['Tag' => $tag, 'Top' => ['type' => 'object']], ['Tag' => ['allOf' => [['$ref' => '#/components/schemas/Top'], $tag]], 'Top' => ['type' => 'object', 'properties' => ['t' => ['type' => 'string']]]]],
            'variants' => [
                ['Tag' => $base + ['discriminator' => ['propertyName' => 'kind']], 'A' => $variant, 'B' => $variant],
                ['Tag' => $base + ['discriminator' => ['propertyName' => 'kind', 'mapping' => ['x' => '#/components/schemas/A', 'y' => '#/components/schemas/B']]], 'A' => $variant, 'B' => $variant],
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
        $merged = (new Views(new SchemaGraph([]), new ViewSuffixes()))->merge(ModelFixture::build($read), ModelFixture::build($write));

        self::assertContains(
            'error /project/api/openapi.yaml#/components/schemas/Tag: App\Dto\Tag comes out differently in the read and the write build, which is a defect of the generator; please report it.',
            ModelFixture::messages($merged),
        );
    }

    public function testBuildsOnceWithoutDependentClasses(): void
    {
        $single = ModelFixture::build(['Tag' => ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]], 'Old' => ['type' => 'object', 'properties' => ['a' => ['type' => 'string']], 'oneOf' => [['required' => ['a']], ['type' => 'object']]]]);
        $builds = 0;

        $output = (new Views(new SchemaGraph([]), new ViewSuffixes()))->build(static function (?View $view) use ($single, &$builds): Run {
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
        $merged = (new Views(new SchemaGraph([]), new ViewSuffixes()))->merge(
            ModelFixture::build(['Red' => ['type' => 'string', 'enum' => ['red']]]),
            ModelFixture::build(['Red' => ['type' => 'string', 'enum' => ['red']], 'Blue' => ['type' => 'string', 'enum' => ['blue']]]),
        );

        self::assertSame(['App\Dto\Red', 'App\Dto\Blue'], ModelFixture::enums($merged));
    }

    public function testKeepsASharedClassBothBuildsAgreeOn(): void
    {
        $tag = [
            'Tag' => ['type' => 'object', 'required' => ['kind'], 'properties' => ['kind' => ['type' => 'string']], 'discriminator' => ['propertyName' => 'kind']],
            'A' => ['allOf' => [['$ref' => '#/components/schemas/Tag'], ['type' => 'object', 'properties' => ['v' => ['type' => 'string']]]]],
        ];

        $merged = (new Views(new SchemaGraph([]), new ViewSuffixes()))->merge(ModelFixture::build($tag), ModelFixture::build($tag));

        self::assertSame(['App\Dto\Tag', 'App\Dto\A'], array_keys(ModelFixture::classes($merged)));
        self::assertSame([], ModelFixture::messages($merged));
    }
}
