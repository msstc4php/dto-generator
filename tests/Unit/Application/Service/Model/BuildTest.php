<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Application\Service\Model;

use MSSTC4PHP\DtoGenerator\Application\Service\Model\Build\Action;
use MSSTC4PHP\DtoGenerator\Application\Service\Model\Build\Input;
use MSSTC4PHP\DtoGenerator\Application\Service\Schemas\Load\Action as LoadSchemas;
use MSSTC4PHP\DtoGenerator\Application\Service\Schemas\Load\Input as SchemasInput;
use MSSTC4PHP\DtoGenerator\Domain\Builder\NameResolver;
use MSSTC4PHP\DtoGenerator\Domain\Builder\SchemaParser;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostic;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassType;
use MSSTC4PHP\DtoGenerator\Domain\Model\ScalarType;
use MSSTC4PHP\DtoGenerator\Domain\Target\AccessorStyle;
use MSSTC4PHP\DtoGenerator\Domain\Target\DateTimeClass;
use MSSTC4PHP\DtoGenerator\Domain\Target\MetadataMode;
use MSSTC4PHP\DtoGenerator\Domain\Target\Mutability;
use MSSTC4PHP\DtoGenerator\Domain\Target\PhpVersion;
use MSSTC4PHP\DtoGenerator\Domain\Target\TargetProfile;
use MSSTC4PHP\DtoGenerator\Tests\Support\ConfigMother;
use MSSTC4PHP\DtoGenerator\Tests\Support\EmitterFixture;
use MSSTC4PHP\DtoGenerator\Tests\Support\GraphFixture;
use MSSTC4PHP\DtoGenerator\Tests\Support\InMemoryDocumentLoader;
use MSSTC4PHP\DtoGenerator\Tests\Support\ModelFixture;
use PHPUnit\Framework\TestCase;

final class BuildTest extends TestCase
{
    private const AT = '/project/api/openapi.yaml#/components/schemas/';

    public function testLeavesTheExtensionsOfASchemaOnlyAKeywordReachesAlone(): void
    {
        $output = ModelFixture::build(
            ['User' => ['type' => 'object', 'propertyNames' => ['$ref' => '#/components/schemas/Name'], 'properties' => ['id' => ['type' => 'string']]], 'Name' => ['type' => 'string', 'x-php-nmae' => 'x', 'x-php-class-name' => 'N']],
            [],
            ['User'],
        );

        self::assertFalse($output->diagnostics()->hasErrors());
        self::assertSame([], array_values(array_filter(array_map(static fn (Diagnostic $d): string => $d->toString(), $output->diagnostics()->all()), static fn (string $message): bool => strpos($message, 'Name') !== false)));
    }

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
        $other = ['type' => 'object', 'properties' => ['code' => []]];
        $at = self::AT;

        return [
            'case-only collision' => [
                ['User' => $object, 'user' => ['type' => 'object', 'properties' => ['name' => []]], 'USER' => $other, 'Tag' => $object],
                ['App\Dto\User', 'App\Dto\Tag', 'App\Dto\User2', 'App\Dto\USER3'],
                [
                    "warning {$at}user: Class App\\Dto\\User is already generated from {$at}User, so this one becomes App\\Dto\\User2; set \"x-php-class-name\" to name it, in the document or \"classNames\" of the source.",
                    "warning {$at}USER: Class App\\Dto\\USER is already generated from {$at}User, so this one becomes App\\Dto\\USER3; set \"x-php-class-name\" to name it, in the document or \"classNames\" of the source.",
                ],
            ],
            'separator collision of the same schema' => [
                ['user_profile' => $object, 'UserProfile' => $object],
                ['App\\Dto\\UserProfile', 'App\\Dto\\UserProfile2'],
                ["warning {$at}UserProfile: Class App\\Dto\\UserProfile is already generated from {$at}user_profile, so this one becomes App\\Dto\\UserProfile2; set \"x-php-class-name\" to name it, in the document or \"classNames\" of the source."],
            ],
            'a number another schema holds' => [
                ['Notary' => $object, 'notary' => $other, 'Notary2' => $object],
                ['App\\Dto\\Notary', 'App\\Dto\\Notary2', 'App\\Dto\\Notary3'],
                ["warning {$at}notary: Class App\\Dto\\Notary is already generated from {$at}Notary, so this one becomes App\\Dto\\Notary3; set \"x-php-class-name\" to name it, in the document or \"classNames\" of the source."],
            ],
            'an enum and a class' => [
                ['Status' => ['type' => 'string', 'enum' => ['on']], 'status' => $object],
                ['App\\Dto\\Status2'],
                ["warning {$at}status: Class App\\Dto\\Status is already generated from {$at}Status, so this one becomes App\\Dto\\Status2; set \"x-php-class-name\" to name it, in the document or \"classNames\" of the source."],
            ],
            'a name an explicit one holds' => [
                ['Person' => $other + ['x-php-class-name' => 'User'], 'user' => $object],
                ['App\\Dto\\User', 'App\\Dto\\User2'],
                ["warning {$at}user: Class App\\Dto\\User is already generated from {$at}Person, so this one becomes App\\Dto\\User2; set \"x-php-class-name\" to name it, in the document or \"classNames\" of the source."],
            ],
            'collision with an explicit name' => [
                ['User' => $object, 'Person' => $other + ['x-php-class-name' => 'user']],
                ['App\Dto\User'],
                ["error {$at}Person: Class App\\Dto\\user is already generated from {$at}User; set \"x-php-class-name\" on one of them, in the document or \"classNames\" of the source."],
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
                ["error {$at}***: Schema name \"***\" has no usable characters; set \"x-php-class-name\", in the document or \"classNames\" of the source."],
            ],
        ];
    }

    public function testNamesReferencedPlacesThatTakeOneNameApart(): void
    {
        $at = self::AT;
        $replication = ['type' => 'string', 'enum' => ['none', 'asynch']];
        $output = ModelFixture::build([
            'MySql' => ['type' => 'object', 'properties' => ['replication_type' => $replication, 'hosts' => ['type' => 'object', 'properties' => ['primary' => []]]]],
            'Postgres' => ['type' => 'object', 'properties' => ['replication_type' => $replication, 'hosts' => ['type' => 'object', 'properties' => ['standby' => []]]]],
            'MySqlRequest' => ['type' => 'object', 'properties' => [
                'replication_type' => ['$ref' => '#/components/schemas/MySql/properties/replication_type'],
                'hosts' => ['$ref' => '#/components/schemas/MySql/properties/hosts'],
            ]],
            'PostgresRequest' => ['type' => 'object', 'properties' => [
                'replication_type' => ['$ref' => '#/components/schemas/Postgres/properties/replication_type'],
                'hosts' => ['$ref' => '#/components/schemas/Postgres/properties/hosts'],
            ]],
        ]);

        self::assertSame(
            [
                "warning {$at}Postgres/properties/hosts: Class App\\Dto\\Hosts is already generated from {$at}MySql/properties/hosts, so this one becomes App\\Dto\\Hosts2; set \"x-php-class-name\" to name it, in the document or \"classNames\" of the source."],
            ModelFixture::messages($output),
        );
        self::assertSame(['replication_type: App\Dto\ReplicationType|null', 'hosts: App\Dto\Hosts2|null'], ModelFixture::classes($output)['App\Dto\PostgresRequest']);
        self::assertSame(['replication_type: App\Dto\ReplicationType|null', 'hosts: App\Dto\Hosts2|null'], ModelFixture::classes($output)['App\Dto\Postgres']);
    }

    public function testNumbersReferencedEnumsThatDiffer(): void
    {
        $at = self::AT;
        $output = ModelFixture::build([
            'MySql' => ['type' => 'object', 'properties' => ['replication_type' => ['type' => 'string', 'enum' => ['none', 'asynch']]]],
            'Postgres' => ['type' => 'object', 'properties' => ['replication_type' => ['type' => 'string', 'enum' => ['none', 'semi_synch']]]],
            'MySqlRequest' => ['type' => 'object', 'properties' => ['replication_type' => ['$ref' => '#/components/schemas/MySql/properties/replication_type']]],
            'PostgresRequest' => ['type' => 'object', 'properties' => ['replication_type' => ['$ref' => '#/components/schemas/Postgres/properties/replication_type']]],
        ]);

        self::assertSame(
            ["warning {$at}Postgres/properties/replication_type: Class App\\Dto\\ReplicationType is already generated from {$at}MySql/properties/replication_type, so this one becomes App\\Dto\\ReplicationType2; set \"x-php-class-name\" to name it, in the document or \"classNames\" of the source."],
            ModelFixture::messages($output),
        );
        self::assertSame(['replication_type: App\Dto\ReplicationType2|null'], ModelFixture::classes($output)['App\Dto\PostgresRequest']);
    }

    public function testKeepsTheDiscriminatorValueAndTheParentOfANumberedSchema(): void
    {
        $object = ['type' => 'object', 'required' => ['kind'], 'properties' => ['kind' => ['type' => 'string']]];
        $output = ModelFixture::build([
            'Pet' => ['oneOf' => [['$ref' => '#/components/schemas/Notary'], ['$ref' => '#/components/schemas/notary']], 'discriminator' => ['propertyName' => 'kind']],
            'Notary' => $object,
            'notary' => $object + ['description' => 'Another.'],
            'Child' => ['allOf' => [['$ref' => '#/components/schemas/notary'], ['properties' => ['age' => ['type' => 'integer']]]]],
        ]);

        self::assertSame('abstract by kind {Notary: App\\Dto\\Notary, notary: App\\Dto\\Notary2}', ModelFixture::hierarchy($output)['App\\Dto\\Pet']);
        self::assertSame('final extends App\\Dto\\Notary2', ModelFixture::hierarchy($output)['App\\Dto\\Child']);
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
        $output = (new Action(new NameResolver()))(new Input($config, $target, $graph, []));

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
        $known = 'known: x-php-class-name, x-php-name, x-php-type, x-dto-mutable, x-php-all-of, x-php-skip, x-php-attributes, x-php-read-write, x-enum-descriptions, x-enum-varnames.';
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

    public function testNumbersAnInlineNameThatIsTaken(): void
    {
        $at = self::AT;
        $output = ModelFixture::build([
            'User' => ['type' => 'object', 'properties' => [
                'address' => ['type' => 'object', 'properties' => ['city' => []]],
                'home' => ['type' => 'object', 'x-php-class-name' => 'UserAddress', 'properties' => ['n' => []]],
            ]],
            'UserAddress' => ['type' => 'object', 'properties' => ['street' => []]],
            'UserAddress2' => ['type' => 'object', 'properties' => ['zip' => []]],
        ]);

        self::assertSame(
            [
                "warning {$at}User/properties/address: Class App\\Dto\\UserAddress is already generated from {$at}UserAddress, so this one becomes App\\Dto\\UserAddress3; set \"x-php-class-name\" to name it, in the document or \"classNames\" of the source.",
                "error {$at}User/properties/home: Class App\\Dto\\UserAddress is already generated from {$at}UserAddress; set \"x-php-class-name\" on one of them, in the document or \"classNames\" of the source.",
            ],
            ModelFixture::messages($output),
        );
        self::assertSame(['address: App\Dto\UserAddress3|null', 'home: mixed'], ModelFixture::classes($output)['App\Dto\User']);
    }

    public function testGivesARepeatedInlineSchemaTheClassOfTheSchemaHoldingItsName(): void
    {
        $point = ['type' => 'object', 'title' => 'Point', 'properties' => ['x' => [], 'near' => ['type' => 'object', 'properties' => ['d' => []]]]];
        $level = ['type' => 'string', 'title' => 'Level', 'enum' => ['low', 'high']];
        $output = ModelFixture::build([
            'Location' => ['type' => 'object', 'title' => 'Location', 'properties' => ['city' => []]],
            'User' => ['type' => 'object', 'properties' => [
                'a' => ['oneOf' => [$point, ['type' => 'string']]],
                'b' => ['oneOf' => [$point, ['type' => 'integer']]],
                'c' => ['oneOf' => [['type' => 'object', 'title' => 'Location', 'properties' => ['city' => []]], ['type' => 'boolean']]],
                'd' => ['oneOf' => [$level, ['type' => 'integer']]],
                'e' => ['oneOf' => [$level, ['type' => 'boolean']]],
            ]],
            'Shop' => ['type' => 'object', 'properties' => ['f' => ['oneOf' => [$point + ['description' => 'Another.'], ['type' => 'string']]]]],
        ]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(
            [
                'App\\Dto\\Location' => ['city: mixed'],
                'App\\Dto\\User' => [
                    'a: App\\Dto\\Point|string|null',
                    'b: App\\Dto\\Point|int|null',
                    'c: App\\Dto\\Location|bool|null',
                    'd: App\\Dto\\Level|int|null',
                    'e: App\\Dto\\Level|bool|null',
                ],
                'App\\Dto\\Shop' => ['f: App\\Dto\\ShopFOption1|string|null'],
                'App\\Dto\\Point' => ['x: mixed', 'near: App\\Dto\\PointNear|null'],
                'App\\Dto\\ShopFOption1' => ['x: mixed', 'near: App\\Dto\\ShopFOption1Near|null'],
                'App\\Dto\\PointNear' => ['d: mixed'],
                'App\\Dto\\ShopFOption1Near' => ['d: mixed'],
            ],
            ModelFixture::classes($output),
        );
        self::assertSame(['App\\Dto\\Level'], ModelFixture::enums($output));
    }

    public function testSharesTheViewsOfARepeatedInlineSchema(): void
    {
        $meta = ['type' => 'object', 'properties' => ['id' => ['type' => 'string', 'readOnly' => true], 'name' => ['type' => 'string']]];
        $point = ['oneOf' => [['title' => 'Point'] + $meta, ['type' => 'string']]];
        $output = ModelFixture::views(['A' => ['type' => 'object', 'properties' => ['p' => $point]], 'B' => ['type' => 'object', 'properties' => ['q' => $point]]]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(
            [
                'App\\Dto\\ARead' => ['p: App\\Dto\\PointRead|string|null'],
                'App\\Dto\\BRead' => ['q: App\\Dto\\PointRead|string|null'],
                'App\\Dto\\PointRead' => ['id: string|null', 'name: string|null'],
                'App\\Dto\\AWrite' => ['p: App\\Dto\\PointWrite|string|null'],
                'App\\Dto\\BWrite' => ['q: App\\Dto\\PointWrite|string|null'],
                'App\\Dto\\PointWrite' => ['name: string|null'],
            ],
            ModelFixture::classes($output),
        );
    }

    /**
     * @dataProvider joinedPlaces
     *
     * @param array<string, array<array-key, mixed>> $schemas
     * @param array<string, list<string>> $expected
     */
    public function testSharesNoClassThatTakesThePropertiesOfAnAllOf(array $schemas, array $expected, int $renamed): void
    {
        $output = ModelFixture::build($schemas);

        self::assertCount($renamed, ModelFixture::messages($output));
        self::assertSame($expected, array_intersect_key(ModelFixture::classes($output), $expected));
    }

    /**
     * @return iterable<string, array{array<string, array<array-key, mixed>>, array<string, list<string>>, int}>
     */
    public static function joinedPlaces(): iterable
    {
        $x = ['type' => 'object', 'properties' => ['x' => []]];
        $alone = ['type' => 'object', 'properties' => ['b_meta' => $x]];
        $joined = ['allOf' => [['type' => 'object', 'properties' => ['level' => ['type' => 'string', 'enum' => ['a']], 'meta' => $x]], ['type' => 'object', 'properties' => ['meta' => ['type' => 'object', 'properties' => ['y' => []]]]]]];

        yield 'the joined place after' => [
            ['A' => $alone, 'AB' => $joined],
            ['App\\Dto\\A' => ['b_meta: App\\Dto\\ABMeta|null'], 'App\\Dto\\AB' => ['level: App\\Dto\\ABLevel|null', 'meta: App\\Dto\\ABMeta2|null'], 'App\\Dto\\ABMeta' => ['x: mixed'], 'App\\Dto\\ABMeta2' => ['x: mixed', 'y: mixed']],
            1,
        ];
        $option = ['oneOf' => [['type' => 'object', 'properties' => ['x' => []]], ['type' => 'string']]];
        yield 'a place of union members, which never join' => [
            ['AB' => ['allOf' => [['type' => 'object', 'properties' => ['p' => $option]], ['type' => 'object', 'properties' => ['p' => $option]]]], 'ABP' => ['type' => 'object', 'properties' => ['option1' => $x]]],
            ['App\\Dto\\ABP' => ['option1: App\\Dto\\ABPOption1|null'], 'App\\Dto\\ABPOption1' => ['x: mixed']],
            0,
        ];
        yield 'the joined place first' => [
            ['AB' => $joined, 'A' => $alone],
            ['App\\Dto\\AB' => ['level: App\\Dto\\ABLevel|null', 'meta: App\\Dto\\ABMeta|null'], 'App\\Dto\\A' => ['b_meta: App\\Dto\\ABMeta2|null'], 'App\\Dto\\ABMeta' => ['x: mixed', 'y: mixed'], 'App\\Dto\\ABMeta2' => ['x: mixed']],
            1,
        ];
    }

    public function testReportsAnEnumItCannotBuildOnce(): void
    {
        $at = self::AT;
        $output = ModelFixture::build([
            'User' => ['type' => 'object', 'properties' => ['level' => ['$ref' => '#/components/schemas/Level']]],
            'Level' => ['enum' => ['in-progress', 'in_progress']],
        ]);

        self::assertSame(["error {$at}Level/enum/1: Enum values \"in-progress\" and \"in_progress\" both become case IN_PROGRESS."], ModelFixture::messages($output));
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
        $output = ModelFixture::build(['User' => ['type' => 'object', 'properties' => ['level' => ['enum' => ['in-progress', 'in_progress']]]]]);

        self::assertSame(["error {$at}User/properties/level/enum/1: Enum values \"in-progress\" and \"in_progress\" both become case IN_PROGRESS."], ModelFixture::messages($output));
        self::assertSame(['App\\Dto\\User' => ['level: mixed']], ModelFixture::classes($output));
    }

    public function testFindsInlineDeclarationsAfterSkippedAndUnnamedProperties(): void
    {
        $at = self::AT;
        $output = ModelFixture::build(['User' => ['type' => 'object', 'properties' => [
            'hidden' => ['type' => 'object', 'x-php-skip' => true, 'properties' => ['x' => []]],
            '§§§' => ['type' => 'object', 'properties' => ['x' => []]],
            'grid' => ['type' => 'array', 'items' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['cell' => ['type' => 'string']]]]],
        ]]]);

        self::assertSame(
            [
                "error {$at}User/properties/§§§: Property name \"§§§\" gives no class name for its inline schema; set \"x-php-class-name\", in the document or \"classNames\" of the source.",
                "warning {$at}User/properties/§§§: Property name \"§§§\" has no usable characters, so the class leaves it out; set \"x-php-name\" to keep it, in the document or \"propertyNames\" of the source.",
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
            ["error {$at}User/properties/***: Property name \"***\" gives no class name for its inline schema; set \"x-php-class-name\", in the document or \"classNames\" of the source."],
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
            'Level' => ['enum' => ['low'], 'x-php-class-name' => 'Grade', 'x-enum-descriptions' => ['low' => 'Low.']],
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
                "error {$at}User/properties/scores/additionalProperties/x-php-foo: Unknown extension \"x-php-foo\"; known: x-php-class-name, x-php-name, x-php-type, x-dto-mutable, x-php-all-of, x-php-skip, x-php-attributes, x-php-read-write, x-enum-descriptions, x-enum-varnames.",
                "warning {$at}User/properties/grid/items/additionalProperties/x-dto-mutable: \"x-dto-mutable\" has no effect here.",
                "error {$at}User/properties/lists/additionalProperties/items/x-php-bar: Unknown extension \"x-php-bar\"; known: x-php-class-name, x-php-name, x-php-type, x-dto-mutable, x-php-all-of, x-php-skip, x-php-attributes, x-php-read-write, x-enum-descriptions, x-enum-varnames.",
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

    public function testMapsFormatsToTheGivenTypes(): void
    {
        $graph = GraphFixture::load(['User' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string', 'format' => 'uuid'], 'at' => ['type' => 'string', 'format' => 'day']]]]);
        $input = new Input(
            ConfigMother::config(ConfigMother::source(GraphFixture::SPEC)),
            EmitterFixture::target('8.2', Mutability::IMMUTABLE),
            $graph,
            ['uuid' => new ClassType(ClassName::fromFqcn('App\Uuid')), 'day' => ScalarType::string('non-empty-string')],
        );

        $output = (new Action(new NameResolver()))($input);

        self::assertSame(['id: App\Uuid|null', 'at: non-empty-string|null'], ModelFixture::classes($output)['App\Dto\User']);
    }

    public function testAcceptsAliasKeysOnClassesAndProperties(): void
    {
        $at = self::AT;
        $graph = GraphFixture::load(['User' => [
            'type' => 'object',
            'x-audit' => 'high',
            'properties' => ['id' => ['type' => 'string', 'x-audit' => 'low'], 'tags' => ['type' => 'array', 'items' => ['type' => 'string', 'x-audit' => 'none']]],
        ]]);
        $input = new Input(ConfigMother::config(ConfigMother::source(GraphFixture::SPEC)), EmitterFixture::target('8.2', Mutability::IMMUTABLE), $graph, [], ['x-audit']);

        $output = (new Action(new NameResolver()))($input);

        self::assertSame(["warning {$at}User/properties/tags/items/x-audit: \"x-audit\" has no effect here."], ModelFixture::messages($output));
    }

    public function testWarnsAboutAttributesWhereNothingCarriesThem(): void
    {
        $at = self::AT;
        $graph = GraphFixture::load([
            'Status' => ['type' => 'string', 'enum' => ['a'], 'x-php-attributes' => [['class' => 'App\A']], 'x-audit' => 1],
            'Pet' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
            'Cat' => ['allOf' => [['$ref' => '#/components/schemas/Pet'], [
                'x-php-attributes' => [['class' => 'App\Feline']],
                'x-php-bogus' => 1,
                'properties' => ['lives' => ['type' => 'integer']],
                'additionalProperties' => ['type' => 'string', 'x-php-odd' => 1],
                'allOf' => [['x-dto-mutable' => true]],
            ]]],
        ]);
        $input = new Input(ConfigMother::config(ConfigMother::source(GraphFixture::SPEC)), EmitterFixture::target('8.2', Mutability::IMMUTABLE), $graph, [], ['x-audit']);

        $output = (new Action(new NameResolver()))($input);

        self::assertSame(
            [
                "warning {$at}Status/x-php-attributes: \"x-php-attributes\" has no effect here.",
                "warning {$at}Status/x-audit: \"x-audit\" has no effect here.",
                "warning {$at}Cat/allOf/1/x-php-attributes: \"x-php-attributes\" has no effect here.",
                "error {$at}Cat/allOf/1/x-php-bogus: Unknown extension \"x-php-bogus\"; known: x-php-class-name, x-php-name, x-php-type, x-dto-mutable, x-php-all-of, x-php-skip, x-php-attributes, x-php-read-write, x-enum-descriptions, x-enum-varnames.",
                "error {$at}Cat/allOf/1/additionalProperties/x-php-odd: Unknown extension \"x-php-odd\"; known: x-php-class-name, x-php-name, x-php-type, x-dto-mutable, x-php-all-of, x-php-skip, x-php-attributes, x-php-read-write, x-enum-descriptions, x-enum-varnames.",
                "warning {$at}Cat/allOf/1/allOf/0/x-dto-mutable: \"x-dto-mutable\" has no effect here.",
            ],
            ModelFixture::messages($output),
        );
    }

    public function testChecksTheMembersOfAnInlineComposition(): void
    {
        $at = self::AT;
        $output = ModelFixture::build([
            'Base' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']]],
            'Order' => ['type' => 'object', 'properties' => [
                'comp' => ['allOf' => [['$ref' => '#/components/schemas/Base'], [
                    'type' => 'object',
                    'x-php-attributes' => [['class' => 'App\\Attr\\OnMember']],
                    'x-php-typo' => 1,
                    'properties' => ['z' => ['type' => 'string']],
                ]]],
                'list' => ['type' => 'array', 'items' => ['allOf' => [['$ref' => '#/components/schemas/Base'], ['properties' => ['w' => []], 'x-php-name' => 'w']]]],
            ]],
        ]);

        self::assertSame(
            [
                "warning {$at}Order/properties/comp/allOf/1/x-php-attributes: \"x-php-attributes\" has no effect here.",
                "error {$at}Order/properties/comp/allOf/1/x-php-typo: Unknown extension \"x-php-typo\"; known: x-php-class-name, x-php-name, x-php-type, x-dto-mutable, x-php-all-of, x-php-skip, x-php-attributes, x-php-read-write, x-enum-descriptions, x-enum-varnames.",
                "warning {$at}Order/properties/list/items/allOf/1/x-php-name: \"x-php-name\" has no effect here.",
            ],
            ModelFixture::messages($output),
        );
    }
}
