<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Application\Service\Model;

use MSSTC4PHP\DtoGenerator\Tests\Support\ModelFixture;
use PHPUnit\Framework\TestCase;

final class BuildTest extends TestCase
{
    private const AT = '/project/api/openapi.yaml#/components/schemas/';

    public function testBuildsAClassPerObjectSchema(): void
    {
        $output = ModelFixture::build([
            'User' => ['type' => 'object', 'required' => ['id'], 'properties' => [
                'id' => ['type' => 'integer'],
                'tag' => ['$ref' => '#/components/schemas/Tag'],
                'email' => ['$ref' => '#/components/schemas/Email'],
            ]],
            'Tag' => ['type' => 'object', 'properties' => ['label' => ['type' => 'string']]],
            'Email' => ['type' => 'string', 'format' => 'email'],
            'Free' => ['type' => 'object'],
        ]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(
            [
                'App\Dto\User' => ['id: int', 'tag: App\Dto\Tag|null', 'email: string|null'],
                'App\Dto\Tag' => ['label: string|null'],
            ],
            ModelFixture::classes($output),
        );
        self::assertSame([0, 0], ModelFixture::sources($output));
    }

    public function testGeneratesSchemasThatAreOnlyReferenced(): void
    {
        $output = ModelFixture::build(
            [
                'User' => ['type' => 'object', 'properties' => [
                    'tag' => ['$ref' => '#/components/schemas/Tag'],
                    'salary' => ['$ref' => '../shared/common.json#/Money'],
                ]],
                'Tag' => ['type' => 'object', 'properties' => ['label' => ['type' => 'string']]],
                'Unused' => ['type' => 'object', 'properties' => ['x' => ['type' => 'string']]],
            ],
            ['/project/shared/common.json' => ['Money' => ['type' => 'object', 'properties' => ['amount' => ['type' => 'string']]]]],
            ['User'],
        );

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(['App\Dto\User', 'App\Dto\Tag', 'App\Dto\Money'], array_keys(ModelFixture::classes($output)));
    }

    public function testNamesClassesFromSchemaNamesOrOverrides(): void
    {
        $output = ModelFixture::build([
            'user_profile' => ['type' => 'object', 'properties' => ['id' => []]],
            'list' => ['type' => 'object', 'properties' => ['id' => []]],
            'Order' => ['type' => 'object', 'x-php-class-name' => 'PurchaseOrder', 'properties' => ['id' => []]],
        ]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(['App\Dto\UserProfile', 'App\Dto\List_', 'App\Dto\PurchaseOrder'], array_keys(ModelFixture::classes($output)));
    }

    /**
     * @dataProvider namingProblems
     *
     * @param array<int|string, array<array-key, mixed>> $schemas
     * @param list<string> $classes
     * @param list<string> $messages
     */
    public function testReportsNamingProblems(array $schemas, array $classes, array $messages): void
    {
        $output = ModelFixture::build($schemas);

        self::assertSame($messages, ModelFixture::messages($output));
        self::assertSame($classes, array_keys(ModelFixture::classes($output)));
    }

    /**
     * @return array<string, array{array<int|string, array<array-key, mixed>>, list<string>, list<string>}>
     */
    public static function namingProblems(): array
    {
        $object = ['type' => 'object', 'properties' => ['id' => []]];
        $at = self::AT;

        return [
            'case-only collision' => [
                ['User' => $object, 'user' => $object],
                ['App\Dto\User'],
                ["error {$at}user: Class App\\Dto\\User is already generated from {$at}User; set \"x-php-class-name\" on one of them."],
            ],
            'separator collision' => [
                ['user_profile' => $object, 'UserProfile' => $object],
                ['App\Dto\UserProfile'],
                ["error {$at}UserProfile: Class App\\Dto\\UserProfile is already generated from {$at}user_profile; set \"x-php-class-name\" on one of them."],
            ],
            'invalid override' => [
                ['User' => $object + ['x-php-class-name' => 'List']],
                [],
                ["error {$at}User/x-php-class-name: \"x-php-class-name\" must be a PHP identifier that is not a reserved word."],
            ],
            'no usable name' => [
                ['***' => $object],
                [],
                ["error {$at}***: Schema name \"***\" has no usable characters; set \"x-php-class-name\"."],
            ],
        ];
    }

    public function testSkipsSchemasMarkedWithXPhpSkip(): void
    {
        $at = self::AT;
        $output = ModelFixture::build([
            'User' => ['type' => 'object', 'properties' => ['secret' => ['$ref' => '#/components/schemas/Secret']]],
            'Secret' => ['type' => 'object', 'x-php-skip' => true, 'properties' => ['x' => []]],
        ]);

        self::assertSame(['App\Dto\User' => ['secret: mixed']], ModelFixture::classes($output));
        self::assertSame(["warning {$at}User/properties/secret: \$ref points to a schema excluded by \"x-php-skip\"."], ModelFixture::messages($output));
    }

    public function testWarnsAboutSelectedSchemasItCannotBuildYet(): void
    {
        $at = self::AT;
        $output = ModelFixture::build([
            'Currency' => ['type' => 'string', 'enum' => ['EUR']],
            'Pet' => ['oneOf' => [['$ref' => '#/components/schemas/Cat']]],
            'Cat' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
        ]);

        self::assertSame(['App\Dto\Cat'], array_keys(ModelFixture::classes($output)));
        self::assertSame(
            [
                "warning {$at}Currency: \"enum\" is not supported yet, so no class is generated for \"Currency\".",
                "warning {$at}Pet: \"oneOf\" is not supported yet, so no class is generated for \"Pet\".",
            ],
            ModelFixture::messages($output),
        );
    }

    public function testKeepsGraphOrder(): void
    {
        $output = ModelFixture::build([
            'B' => ['type' => 'object', 'properties' => ['a' => ['$ref' => '#/components/schemas/A']]],
            'A' => ['type' => 'object', 'properties' => ['id' => []]],
        ]);

        self::assertSame(['App\Dto\B', 'App\Dto\A'], array_keys(ModelFixture::classes($output)));
    }
}
