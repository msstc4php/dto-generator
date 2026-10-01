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
        $known = 'known: x-php-class-name, x-php-name, x-php-type, x-dto-mutable, x-php-all-of, x-php-skip, x-php-attributes.';
        $output = ModelFixture::build([
            'User' => ['type' => 'object', 'x-dto-mutible' => true, 'x-php-name' => 'u', 'properties' => ['id' => []]],
            'S' => ['type' => 'string', 'x-php-nmae' => 'x', 'x-php-class-name' => 'Foo'],
            'Hidden' => ['type' => 'object', 'x-php-skip' => true, 'x-php-clas-name' => 'X', 'properties' => ['id' => []]],
            'Currency' => ['type' => 'string', 'enum' => ['EUR'], 'x-php-class-name' => 'Money'],
        ]);

        self::assertSame(
            [
                "error {$at}User/x-dto-mutible: Unknown extension \"x-dto-mutible\"; {$known}",
                "warning {$at}User/x-php-name: \"x-php-name\" has no effect here.",
                "error {$at}S/x-php-nmae: Unknown extension \"x-php-nmae\"; {$known}",
                "warning {$at}S/x-php-class-name: \"x-php-class-name\" has no effect here.",
                "error {$at}Hidden/x-php-clas-name: Unknown extension \"x-php-clas-name\"; {$known}",
                "warning {$at}Currency: \"enum\" is not supported yet, so no class is generated for \"Currency\".",
            ],
            ModelFixture::messages($output),
        );
    }

    public function testWarnsAboutCyclesOfRequiredProperties(): void
    {
        $at = self::AT;
        $output = ModelFixture::build([
            'Node' => ['type' => 'object', 'required' => ['parent'], 'properties' => ['parent' => ['$ref' => '#/components/schemas/Node']]],
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
}
