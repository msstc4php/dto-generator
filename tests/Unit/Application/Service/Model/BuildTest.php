<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Application\Service\Model;

use MSSTC4PHP\DtoGenerator\Application\Service\Model\Build\Action;
use MSSTC4PHP\DtoGenerator\Application\Service\Model\Build\Input;
use MSSTC4PHP\DtoGenerator\Application\Service\Schemas\Load\Action as LoadSchemas;
use MSSTC4PHP\DtoGenerator\Application\Service\Schemas\Load\Input as SchemasInput;
use MSSTC4PHP\DtoGenerator\Domain\Builder\NameResolver;
use MSSTC4PHP\DtoGenerator\Domain\Builder\SchemaParser;
use MSSTC4PHP\DtoGenerator\Domain\Target\AccessorStyle;
use MSSTC4PHP\DtoGenerator\Domain\Target\DateTimeClass;
use MSSTC4PHP\DtoGenerator\Domain\Target\MetadataMode;
use MSSTC4PHP\DtoGenerator\Domain\Target\Mutability;
use MSSTC4PHP\DtoGenerator\Domain\Target\PhpVersion;
use MSSTC4PHP\DtoGenerator\Domain\Target\TargetProfile;
use MSSTC4PHP\DtoGenerator\Tests\Support\ConfigMother;
use MSSTC4PHP\DtoGenerator\Tests\Support\InMemoryDocumentLoader;
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
                ['User' => $object, 'user' => $object, 'Tag' => $object],
                ['App\Dto\User', 'App\Dto\Tag'],
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
            'non-string override' => [
                ['User' => $object + ['x-php-class-name' => 5]],
                [],
                ["error {$at}User/x-php-class-name: \"x-php-class-name\" must be a PHP identifier that is not a reserved word."],
            ],
            'no usable name' => [
                ['***' => $object, 'Tag' => $object],
                ['App\Dto\Tag'],
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

    public function testGeneratesNoClassForAUnionWithoutDiscriminator(): void
    {
        $output = ModelFixture::build([
            'Currency' => ['type' => 'string', 'enum' => ['EUR']],
            'Pet' => ['oneOf' => [['$ref' => '#/components/schemas/Cat']]],
            'Cat' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
        ]);

        self::assertSame(['App\Dto\Cat'], array_keys(ModelFixture::classes($output)));
        self::assertSame([], ModelFixture::messages($output));
    }

    public function testKeepsGraphOrder(): void
    {
        $output = ModelFixture::build([
            'B' => ['type' => 'object', 'properties' => ['a' => ['$ref' => '#/components/schemas/A']]],
            'A' => ['type' => 'object', 'properties' => ['id' => []]],
        ]);

        self::assertSame(['App\Dto\B', 'App\Dto\A'], array_keys(ModelFixture::classes($output)));
    }

    public function testKeepsBuildingAfterASkippedSchema(): void
    {
        $output = ModelFixture::build([
            'Secret' => ['type' => 'object', 'x-php-skip' => true, 'properties' => ['x' => []]],
            'User' => ['type' => 'object', 'properties' => ['id' => []]],
        ]);

        self::assertSame(['App\Dto\User'], array_keys(ModelFixture::classes($output)));
    }

    public function testLeavesSchemasWithoutAnOwnerUngenerated(): void
    {
        $documents = [
            '/project/api/openapi.yaml' => ['openapi' => '3.1.0', 'components' => ['schemas' => ['User' => ['type' => 'object', 'properties' => ['m' => ['$ref' => '../shared/common.json#/Money']]]]]],
            '/project/other/openapi.yaml' => ['openapi' => '3.1.0', 'components' => ['schemas' => [
                'Pet' => ['type' => 'object', 'properties' => ['m' => ['$ref' => '../shared/common.json#/Money'], 't' => ['$ref' => '#/components/schemas/Tag']]],
                'Tag' => ['type' => 'object', 'properties' => ['label' => ['type' => 'string']]],
            ]]],
            '/project/shared/common.json' => ['Money' => ['type' => 'object', 'properties' => ['amount' => ['type' => 'string']]]],
        ];
        $config = ConfigMother::config(
            ConfigMother::source('/project/api/openapi.yaml'),
            ConfigMother::source('/project/other/openapi.yaml', ['Pet'], [], 'App\\Other'),
        );
        $graph = (new LoadSchemas(new InMemoryDocumentLoader($documents), new SchemaParser()))(new SchemasInput($config))->graph();
        $target = new TargetProfile(
            PhpVersion::fromString('8.2'),
            MetadataMode::from(MetadataMode::NONE),
            Mutability::from(Mutability::IMMUTABLE),
            AccessorStyle::from(AccessorStyle::AUTO),
            DateTimeClass::from(DateTimeClass::IMMUTABLE),
            true,
        );
        $output = (new Action(new NameResolver()))(new Input($config, $target, $graph));

        self::assertSame(
            ['App\Dto\User' => ['m: mixed'], 'App\Other\Pet' => ['m: mixed', 't: App\Other\Tag|null'], 'App\Other\Tag' => ['label: string|null']],
            ModelFixture::classes($output),
        );
        self::assertSame([], ModelFixture::messages($output));
    }

    public function testAnObjectSchemaWithXPhpTypeBecomesThatClass(): void
    {
        $output = ModelFixture::build([
            'User' => ['type' => 'object', 'properties' => ['money' => ['$ref' => '#/components/schemas/Money']]],
            'Money' => ['type' => 'object', 'x-php-type' => 'Brick\\Money\\Money', 'properties' => ['amount' => []]],
        ]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(['App\Dto\User' => ['money: Brick\Money\Money|null']], ModelFixture::classes($output));
    }

    public function testXPhpSkipWorksOnAliases(): void
    {
        $at = self::AT;
        $output = ModelFixture::build([
            'A' => ['type' => 'object', 'properties' => ['e' => ['$ref' => '#/components/schemas/E'], 'f' => ['$ref' => '#/components/schemas/F']]],
            'E' => ['type' => 'string', 'x-php-skip' => true],
            'F' => ['type' => 'string', 'x-php-skip' => 'yes'],
        ]);

        self::assertSame(['App\Dto\A' => ['e: mixed', 'f: string|null']], ModelFixture::classes($output));
        self::assertSame(
            [
                "error {$at}F/x-php-skip: \"x-php-skip\" must be true or false.",
                "warning {$at}A/properties/e: \$ref points to a schema excluded by \"x-php-skip\".",
            ],
            ModelFixture::messages($output),
        );
    }

    public function testChecksTheExtensionVocabularyOfEverySchema(): void
    {
        $at = self::AT;
        $known = 'known: x-php-class-name, x-php-name, x-php-type, x-dto-mutable, x-php-all-of, x-php-skip, x-php-attributes, x-enum-descriptions.';
        $output = ModelFixture::build([
            'User' => ['type' => 'object', 'x-dto-mutible' => true, 'x-php-name' => 'u', 'properties' => ['id' => []]],
            'S' => ['type' => 'string', 'x-php-nmae' => 'x', 'x-php-class-name' => 'Foo'],
            'Hidden' => ['type' => 'object', 'x-php-skip' => true, 'x-php-clas-name' => 'X', 'properties' => ['id' => []]],
            'Currency' => ['type' => 'string', 'enum' => ['EUR'], 'x-php-class-name' => 'Money'],
            'Tags' => ['type' => 'array', 'items' => ['type' => 'string', 'x-php-nmae' => 'x']],
        ]);

        self::assertSame(
            [
                "error {$at}User/x-dto-mutible: Unknown extension \"x-dto-mutible\"; {$known}",
                "warning {$at}User/x-php-name: \"x-php-name\" has no effect here.",
                "error {$at}S/x-php-nmae: Unknown extension \"x-php-nmae\"; {$known}",
                "warning {$at}S/x-php-class-name: \"x-php-class-name\" has no effect here.",
                "error {$at}Hidden/x-php-clas-name: Unknown extension \"x-php-clas-name\"; {$known}",
                "error {$at}Tags/items/x-php-nmae: Unknown extension \"x-php-nmae\"; {$known}",
            ],
            ModelFixture::messages($output),
        );
    }

    public function testWarnsAboutCyclesOfRequiredProperties(): void
    {
        $at = self::AT;
        $output = ModelFixture::build([
            'Node' => ['type' => 'object', 'required' => ['parent'], 'properties' => ['id' => ['type' => 'integer'], 'parent' => ['$ref' => '#/components/schemas/Node']]],
            'A' => ['type' => 'object', 'required' => ['b'], 'properties' => ['b' => ['$ref' => '#/components/schemas/B']]],
            'B' => ['type' => 'object', 'required' => ['a', 'tree'], 'properties' => ['a' => ['$ref' => '#/components/schemas/A'], 'tree' => ['$ref' => '#/components/schemas/Tree']]],
            'Tree' => ['type' => 'object', 'required' => ['id'], 'properties' => ['id' => ['type' => 'integer'], 'parent' => ['$ref' => '#/components/schemas/Tree']]],
        ]);
        $message = 'Required property "%s" of %s leads back to it through required properties, so no instance can ever be constructed.';

        self::assertSame(
            [
                "warning {$at}Node/properties/parent: " . sprintf($message, 'parent', 'App\Dto\Node'),
                "warning {$at}A/properties/b: " . sprintf($message, 'b', 'App\Dto\A'),
                "warning {$at}B/properties/a: " . sprintf($message, 'a', 'App\Dto\B'),
            ],
            ModelFixture::messages($output),
        );
    }

    public function testFindsRequiredCyclesBehindRepeatedClasses(): void
    {
        $at = self::AT;
        $ref = static fn (string $name): array => ['$ref' => '#/components/schemas/' . $name];
        $output = ModelFixture::build([
            'O' => ['type' => 'object', 'required' => ['p'], 'properties' => ['p' => $ref('P')]],
            'P' => ['type' => 'object', 'required' => ['s', 'q1', 'q2'], 'properties' => ['s' => $ref('S'), 'q1' => $ref('Q'), 'q2' => $ref('Q')]],
            'Q' => ['type' => 'object', 'required' => ['id'], 'properties' => ['id' => ['type' => 'integer']]],
            'S' => ['type' => 'object', 'required' => ['o'], 'properties' => ['o' => $ref('O')]],
        ]);

        self::assertSame(
            [
                "warning {$at}O/properties/p: Required property \"p\" of App\\Dto\\O leads back to it through required properties, so no instance can ever be constructed.",
                "warning {$at}P/properties/s: Required property \"s\" of App\\Dto\\P leads back to it through required properties, so no instance can ever be constructed.",
                "warning {$at}S/properties/o: Required property \"o\" of App\\Dto\\S leads back to it through required properties, so no instance can ever be constructed.",
            ],
            ModelFixture::messages($output),
        );
    }

    public function testBuildsEnumsAndInlineDeclarations(): void
    {
        $output = ModelFixture::build([
            'User' => ['type' => 'object', 'required' => ['currency'], 'properties' => [
                'currency' => ['$ref' => '#/components/schemas/Currency'],
                'status' => ['type' => 'string', 'enum' => ['active', 'blocked', null]],
                'address' => ['type' => 'object', 'properties' => [
                    'city' => ['type' => 'string'],
                    'geo' => ['type' => 'object', 'properties' => ['lat' => ['type' => 'number']]],
                ]],
                'tags' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['label' => ['type' => 'string']]]],
                'extra' => ['type' => 'object', 'x-php-class-name' => 'Extras', 'properties' => ['note' => ['type' => 'string']]],
                'skipped' => ['type' => 'object', 'x-php-skip' => true, 'properties' => ['x' => []]],
                'counts' => ['type' => 'object', 'additionalProperties' => ['type' => 'integer']],
            ]],
            'Currency' => ['type' => 'string', 'enum' => ['EUR', 'USD']],
        ]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(
            [
                'App\Dto\User' => [
                    'currency: App\Dto\Currency',
                    'status: App\Dto\UserStatus|null',
                    'address: App\Dto\UserAddress|null',
                    'tags: list<App\Dto\UserTagsItem>|null',
                    'extra: App\Dto\Extras|null',
                    'counts: array<array-key, int>|null',
                ],
                'App\Dto\UserAddress' => ['city: string|null', 'geo: App\Dto\UserAddressGeo|null'],
                'App\Dto\UserTagsItem' => ['label: string|null'],
                'App\Dto\Extras' => ['note: string|null'],
                'App\Dto\UserAddressGeo' => ['lat: float|null'],
            ],
            ModelFixture::classes($output),
        );
        self::assertSame(['App\Dto\Currency', 'App\Dto\UserStatus'], ModelFixture::enums($output));
    }

    public function testRefusesAnInlineNameThatIsTaken(): void
    {
        $at = self::AT;
        $output = ModelFixture::build([
            'User' => ['type' => 'object', 'properties' => ['address' => ['type' => 'object', 'properties' => ['city' => []]]]],
            'UserAddress' => ['type' => 'object', 'properties' => ['street' => []]],
        ]);

        self::assertSame(
            ["error {$at}User/properties/address: Class App\\Dto\\UserAddress is already generated from {$at}UserAddress; set \"x-php-class-name\" on one of them."],
            ModelFixture::messages($output),
        );
        self::assertSame(['App\Dto\User' => ['address: mixed'], 'App\Dto\UserAddress' => ['street: mixed']], ModelFixture::classes($output));
    }

    public function testReportsAnEnumItCannotBuildOnce(): void
    {
        $at = self::AT;
        $output = ModelFixture::build([
            'User' => ['type' => 'object', 'properties' => ['level' => ['$ref' => '#/components/schemas/Level']]],
            'Level' => ['enum' => ['low', 1]],
        ]);

        self::assertSame(["error {$at}Level/enum: The enum mixes strings and integers, which no PHP enum can back."], ModelFixture::messages($output));
        self::assertSame(['App\Dto\User' => ['level: mixed']], ModelFixture::classes($output));
        self::assertSame([], ModelFixture::enums($output));
    }

    public function testTreatsAMapSchemaAsAnAlias(): void
    {
        $output = ModelFixture::build([
            'User' => ['type' => 'object', 'properties' => ['scores' => ['$ref' => '#/components/schemas/Scores']]],
            'Scores' => ['type' => 'object', 'additionalProperties' => ['type' => 'number']],
        ]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(['App\Dto\User' => ['scores: array<array-key, float>|null']], ModelFixture::classes($output));
    }

    public function testReportsAnInlineEnumItCannotBuildOnce(): void
    {
        $at = self::AT;
        $output = ModelFixture::build(['User' => ['type' => 'object', 'properties' => ['level' => ['enum' => ['low', 1]]]]]);

        self::assertSame(["error {$at}User/properties/level/enum: The enum mixes strings and integers, which no PHP enum can back."], ModelFixture::messages($output));
        self::assertSame(['App\\Dto\\User' => ['level: mixed']], ModelFixture::classes($output));
    }

    public function testFindsInlineDeclarationsAfterSkippedAndUnnamedProperties(): void
    {
        $at = self::AT;
        $output = ModelFixture::build(['User' => ['type' => 'object', 'properties' => [
            'hidden' => ['type' => 'object', 'x-php-skip' => true, 'properties' => ['x' => []]],
            '---' => ['type' => 'object', 'properties' => ['x' => []]],
            'grid' => ['type' => 'array', 'items' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['cell' => ['type' => 'string']]]]],
        ]]]);

        self::assertSame(
            [
                "error {$at}User/properties/---: Property name \"---\" gives no class name for its inline schema; set \"x-php-class-name\".",
                "error {$at}User/properties/---: Property name \"---\" has no usable characters; set \"x-php-name\".",
            ],
            ModelFixture::messages($output),
        );
        self::assertSame(['App\\Dto\\User', 'App\\Dto\\UserGridItemItem'], array_keys(ModelFixture::classes($output)));
    }

    public function testDeclaresAPropertySchemaReachedByReferenceOnce(): void
    {
        $output = ModelFixture::build([
            'User' => ['type' => 'object', 'properties' => [
                'address' => ['type' => 'object', 'properties' => ['city' => ['type' => 'string']]],
                'kind' => ['type' => 'string', 'enum' => ['a', 'b']],
            ]],
            'Order' => ['type' => 'object', 'properties' => [
                'ship' => ['$ref' => '#/components/schemas/User/properties/address'],
                'kind' => ['$ref' => '#/components/schemas/User/properties/kind'],
            ]],
        ]);

        self::assertSame([], ModelFixture::messages($output));
        $classes = ModelFixture::classes($output);
        self::assertCount(3, $classes);
        self::assertCount(1, ModelFixture::enums($output));
        $address = array_values(array_diff(array_keys($classes), ['App\\Dto\\User', 'App\\Dto\\Order']))[0];
        self::assertContains('address: ' . $address . '|null', $classes['App\\Dto\\User']);
        self::assertContains('ship: ' . $address . '|null', $classes['App\\Dto\\Order']);
    }

    public function testNamesAnInlineClassOfAnUnnamedPropertyFromItsOverride(): void
    {
        $at = self::AT;
        $output = ModelFixture::build(['User' => ['type' => 'object', 'properties' => [
            "\u{20AC}" => ['type' => 'object', 'x-php-name' => 'euro', 'x-php-class-name' => 'Euro', 'properties' => ['cents' => ['type' => 'integer']]],
            '***' => ['type' => 'object', 'x-php-name' => 'stars', 'properties' => ['n' => ['type' => 'integer']]],
        ]]]);

        self::assertSame(
            ["error {$at}User/properties/***: Property name \"***\" gives no class name for its inline schema; set \"x-php-class-name\"."],
            ModelFixture::messages($output),
        );
        self::assertSame(["\u{20AC}: App\\Dto\\Euro|null", '***: mixed'], ModelFixture::classes($output)['App\\Dto\\User']);
    }

    public function testDeclaresInlineSchemasOfMaps(): void
    {
        $output = ModelFixture::build(['User' => [
            'type' => 'object',
            'properties' => [
                'scores' => ['type' => 'object', 'additionalProperties' => ['type' => 'object', 'properties' => ['value' => ['type' => 'number']]]],
            ],
            'additionalProperties' => ['type' => 'string', 'enum' => ['x', 'y']],
        ]]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(
            ['scores: array<array-key, App\\Dto\\UserScoresValue>|null', 'additionalProperties: array<array-key, App\\Dto\\UserAdditionalProperty>'],
            ModelFixture::classes($output)['App\\Dto\\User'],
        );
        self::assertSame(['App\\Dto\\UserAdditionalProperty'], ModelFixture::enums($output));
    }

    public function testWarnsAboutClassKeysOnPropertiesThatDeclareNothing(): void
    {
        $at = self::AT;
        $output = ModelFixture::build(['User' => ['type' => 'object', 'properties' => [
            'id' => ['type' => 'string', 'x-php-class-name' => 'Id'],
            'tags' => ['type' => 'array', 'items' => ['type' => 'string', 'x-enum-descriptions' => ['a' => 'A']]],
        ]]]);

        self::assertSame(
            [
                "warning {$at}User/properties/id/x-php-class-name: \"x-php-class-name\" has no effect here.",
                "warning {$at}User/properties/tags/items/x-enum-descriptions: \"x-enum-descriptions\" has no effect here.",
            ],
            ModelFixture::messages($output),
        );
    }

    public function testAcceptsTheKeysOfClassesAndEnums(): void
    {
        $output = ModelFixture::build([
            'User' => ['type' => 'object', 'x-dto-mutable' => true, 'x-php-all-of' => 'extends', 'properties' => ['id' => []]],
            'Level' => ['enum' => ['low'], 'x-php-class-name' => 'Grade', 'x-enum-descriptions' => ['low' => 'Low.'], 'x-php-attributes' => [['class' => 'App\\Attr\\Audited']]],
        ]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(['App\\Dto\\Grade'], ModelFixture::enums($output));
    }

    public function testNamesInlineSchemasInMapsInsideArrays(): void
    {
        $output = ModelFixture::build(['User' => ['type' => 'object', 'properties' => [
            'grid' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => ['type' => 'object', 'properties' => ['cell' => []]]]],
        ]]]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(['App\\Dto\\User', 'App\\Dto\\UserGridItemValue'], array_keys(ModelFixture::classes($output)));
    }

    public function testChecksExtensionKeysOfMapValues(): void
    {
        $at = self::AT;
        $output = ModelFixture::build(['User' => [
            'type' => 'object',
            'properties' => [
                'scores' => ['type' => 'object', 'additionalProperties' => ['type' => 'string', 'x-php-class-name' => 'Score', 'x-php-foo' => 1]],
                'grid' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => ['type' => 'string', 'x-dto-mutable' => true]]],
                'lists' => ['type' => 'object', 'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string', 'x-php-bar' => 1]]],
            ],
            'additionalProperties' => ['type' => 'integer', 'x-dto-mutable' => true],
        ]]);

        self::assertSame(
            [
                "warning {$at}User/additionalProperties/x-dto-mutable: \"x-dto-mutable\" has no effect here.",
                "warning {$at}User/properties/scores/additionalProperties/x-php-class-name: \"x-php-class-name\" has no effect here.",
                "error {$at}User/properties/scores/additionalProperties/x-php-foo: Unknown extension \"x-php-foo\"; known: x-php-class-name, x-php-name, x-php-type, x-dto-mutable, x-php-all-of, x-php-skip, x-php-attributes, x-enum-descriptions.",
                "warning {$at}User/properties/grid/items/additionalProperties/x-dto-mutable: \"x-dto-mutable\" has no effect here.",
                "error {$at}User/properties/lists/additionalProperties/items/x-php-bar: Unknown extension \"x-php-bar\"; known: x-php-class-name, x-php-name, x-php-type, x-dto-mutable, x-php-all-of, x-php-skip, x-php-attributes, x-enum-descriptions.",
            ],
            ModelFixture::messages($output),
        );
    }

    public function testTakesClassKeysOnAnInlineMapValue(): void
    {
        $output = ModelFixture::build(['User' => ['type' => 'object', 'properties' => [
            'scores' => ['type' => 'object', 'additionalProperties' => [
                'type' => 'object', 'x-php-class-name' => 'Score', 'x-dto-mutable' => true, 'properties' => ['value' => ['type' => 'number']],
            ]],
        ]]]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(['App\\Dto\\User', 'App\\Dto\\Score'], array_keys(ModelFixture::classes($output)));
    }
}
