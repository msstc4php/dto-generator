<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Builder\ClassBuilder;
use MSSTC4PHP\DtoGenerator\Domain\Builder\Declarations;
use MSSTC4PHP\DtoGenerator\Domain\Builder\Direction;
use MSSTC4PHP\DtoGenerator\Domain\Builder\Directions;
use MSSTC4PHP\DtoGenerator\Domain\Builder\NameResolver;
use MSSTC4PHP\DtoGenerator\Domain\Builder\PropertyView;
use MSSTC4PHP\DtoGenerator\Domain\Builder\ReadWriteMode;
use MSSTC4PHP\DtoGenerator\Domain\Builder\TypeMapper;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostic;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Model\PropertyModel;
use MSSTC4PHP\DtoGenerator\Domain\Shared\DefaultValue;
use MSSTC4PHP\DtoGenerator\Domain\Target\AccessorStyle;
use MSSTC4PHP\DtoGenerator\Domain\Target\DateTimeClass;
use MSSTC4PHP\DtoGenerator\Domain\Target\MetadataMode;
use MSSTC4PHP\DtoGenerator\Domain\Target\Mutability;
use MSSTC4PHP\DtoGenerator\Domain\Target\PhpVersion;
use MSSTC4PHP\DtoGenerator\Domain\Target\ReadWriteModels;
use MSSTC4PHP\DtoGenerator\Domain\Target\TargetProfile;
use MSSTC4PHP\DtoGenerator\Tests\Support\GraphFixture;
use PHPUnit\Framework\TestCase;

final class ClassBuilderTest extends TestCase
{
    private const AT = '/project/api/openapi.yaml#/components/schemas/User';

    public function testMarksOnlyThePropertyThatCollectsUndeclaredOnes(): void
    {
        [$collecting] = $this->build(['type' => 'object', 'properties' => ['id' => ['type' => 'string']], 'additionalProperties' => ['type' => 'integer']]);
        [$declared] = $this->build(['type' => 'object', 'properties' => ['additionalProperties' => ['type' => 'string']]]);

        self::assertTrue($this->property($collecting, 'additionalProperties')->isAdditionalProperties());
        self::assertFalse($this->property($collecting, 'id')->isAdditionalProperties());
        self::assertFalse($this->property($declared, 'additionalProperties')->isAdditionalProperties());
    }

    public function testBuildsPropertiesWithRequiredAndDefaults(): void
    {
        [$class, $messages] = $this->build([
            'type' => 'object',
            'description' => 'A user',
            'deprecated' => true,
            'required' => ['id', 'nickname'],
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'Primary key'],
                'user_name' => ['type' => 'string'],
                'age' => ['type' => 'integer', 'default' => 18],
                'tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'default' => ['a']],
                'nickname' => ['type' => ['string', 'null']],
                'old' => ['type' => 'string', 'deprecated' => true],
            ],
        ]);

        self::assertSame([], $messages);
        self::assertSame('App\Dto\User', $class->name()->fqcn());
        self::assertSame('final', $class->kind()->value());
        self::assertSame('immutable', $class->mutability()->value());
        self::assertSame('A user', $class->doc()->description());
        self::assertTrue($class->doc()->isDeprecated());
        self::assertSame(self::AT, $class->source()->toString());
        self::assertSame(
            [
                'id' => 'id: int',
                'userName' => 'user_name: string|null = NULL',
                'age' => 'age: int|null = 18',
                'tags' => "tags: list<string>|null = array (\n  0 => 'a',\n)",
                'nickname' => 'nickname: string|null = NULL',
                'old' => 'old: string|null = NULL',
            ],
            $this->summary($class),
        );
        self::assertSame('Primary key', $this->property($class, 'id')->doc()->description());
        self::assertTrue($this->property($class, 'old')->doc()->isDeprecated());
        self::assertSame(self::AT . '/properties/user_name', $this->property($class, 'userName')->source()->toString());
    }

    public function testRequiredPropertiesIgnoreTheSchemaDefault(): void
    {
        [$class] = $this->build(['type' => 'object', 'required' => ['id'], 'properties' => ['id' => ['type' => 'integer', 'default' => 1]]]);

        self::assertNull($this->property($class, 'id')->default());
        self::assertTrue($this->property($class, 'id')->isRequired());
    }

    /**
     * @dataProvider problems
     *
     * @param array<array-key, mixed> $schema
     * @param array<string, string> $properties
     * @param list<string> $messages
     */
    public function testReportsProblems(array $schema, array $properties, array $messages): void
    {
        [$class, $actual] = $this->build($schema);

        self::assertSame($messages, $actual);
        self::assertSame($properties, $this->summary($class));
    }

    /**
     * @return array<string, array{array<array-key, mixed>, array<string, string>, list<string>}>
     */
    public static function problems(): array
    {
        $at = self::AT;

        return [
            'names colliding after camelCase' => [
                ['type' => 'object', 'properties' => ['user_name' => ['type' => 'string'], 'userName' => ['type' => 'string'], 'id' => ['type' => 'string']]],
                ['userName' => 'user_name: string|null = NULL', 'userName2' => 'userName: string|null = NULL', 'id' => 'id: string|null = NULL'],
                ["warning {$at}/properties/userName: Property \"userName\" becomes \$userName, which \"user_name\" already uses, so it is \$userName2; set \"x-php-name\" to name it, in the document or \"propertyNames\" of the source."],
            ],
            'a number another name holds' => [
                ['type' => 'object', 'properties' => ['prefs/background' => ['type' => 'string'], 'prefs_background' => ['type' => 'string'], 'prefsBackground2' => ['type' => 'string']]],
                ['prefsBackground' => 'prefs/background: string|null = NULL', 'prefsBackground3' => 'prefs_background: string|null = NULL', 'prefsBackground2' => 'prefsBackground2: string|null = NULL'],
                ["warning {$at}/properties/prefs_background: Property \"prefs_background\" becomes \$prefsBackground, which \"prefs/background\" already uses, so it is \$prefsBackground3; set \"x-php-name\" to name it, in the document or \"propertyNames\" of the source."],
            ],
            'a number another number holds' => [
                ['type' => 'object', 'properties' => ['ab' => ['type' => 'string'], 'a_b' => ['type' => 'string'], 'AB' => ['type' => 'string']]],
                ['ab' => 'ab: string|null = NULL', 'aB2' => 'a_b: string|null = NULL', 'ab3' => 'AB: string|null = NULL'],
                [
                    "warning {$at}/properties/a_b: Property \"a_b\" becomes \$aB, which \"ab\" already uses, so it is \$aB2; set \"x-php-name\" to name it, in the document or \"propertyNames\" of the source.",
                    "warning {$at}/properties/AB: Property \"AB\" becomes \$ab, which \"ab\" already uses, so it is \$ab3; set \"x-php-name\" to name it, in the document or \"propertyNames\" of the source.",
                ],
            ],
            'a number an explicit name further down holds' => [
                ['type' => 'object', 'properties' => ['url' => ['type' => 'string'], 'URL' => ['type' => 'string'], 'link' => ['type' => 'string', 'x-php-name' => 'url2']]],
                ['url' => 'url: string|null = NULL', 'url3' => 'URL: string|null = NULL', 'url2' => 'link: string|null = NULL'],
                ["warning {$at}/properties/URL: Property \"URL\" becomes \$url, which \"url\" already uses, so it is \$url3; set \"x-php-name\" to name it, in the document or \"propertyNames\" of the source."],
            ],
            'a required empty name' => [
                ['type' => 'object', 'required' => [''], 'properties' => ['' => ['type' => 'string']]],
                [],
                ["warning {$at}/properties/: Property name \"\" has no usable characters, so the class leaves it out, though it is required; set \"x-php-name\" to keep it, in the document or \"propertyNames\" of the source."],
            ],
            'an invalid explicit name on a numbered property' => [
                ['type' => 'object', 'properties' => ['url' => ['type' => 'string'], 'URL' => ['type' => 'string', 'x-php-name' => 5]]],
                ['url' => 'url: string|null = NULL', 'url2' => 'URL: string|null = NULL'],
                [
                    "warning {$at}/properties/URL: Property \"URL\" becomes \$url, which \"url\" already uses, so it is \$url2; set \"x-php-name\" to name it, in the document or \"propertyNames\" of the source.",
                    "error {$at}/properties/URL/x-php-name: \"x-php-name\" must be a PHP identifier other than \"this\" and the superglobals.",
                ],
            ],
            'an invalid explicit name beside a sign' => [
                ['type' => 'object', 'properties' => ['+1' => ['type' => 'integer', 'x-php-name' => 'this'], '-1' => ['type' => 'integer']]],
                ['plus1' => '+1: int|null = NULL', 'minus1' => '-1: int|null = NULL'],
                ["error {$at}/properties/+1/x-php-name: \"x-php-name\" must be a PHP identifier other than \"this\" and the superglobals."],
            ],
            'an explicit name another holds' => [
                ['type' => 'object', 'properties' => ['url' => ['type' => 'string'], 'link' => ['type' => 'string', 'x-php-name' => 'URL']]],
                ['url' => 'url: string|null = NULL'],
                ["error {$at}/properties/link: Property \"link\" becomes \$URL, which \"url\" already uses; set \"x-php-name\" on one of them, in the document or \"propertyNames\" of the source."],
            ],
            'an empty name' => [
                ['type' => 'object', 'properties' => ['' => ['type' => 'string'], 'id' => ['type' => 'string']]],
                ['id' => 'id: string|null = NULL'],
                ["warning {$at}/properties/: Property name \"\" has no usable characters, so the class leaves it out; set \"x-php-name\" to keep it, in the document or \"propertyNames\" of the source."],
            ],
            'names colliding by case' => [
                ['type' => 'object', 'properties' => ['URL' => ['type' => 'string'], 'url' => ['type' => 'string']]],
                ['url' => 'URL: string|null = NULL', 'url2' => 'url: string|null = NULL'],
                ["warning {$at}/properties/url: Property \"url\" becomes \$url, which \"URL\" already uses, so it is \$url2; set \"x-php-name\" to name it, in the document or \"propertyNames\" of the source."],
            ],
            'x-php-name' => [
                ['type' => 'object', 'properties' => ['user_name' => ['type' => 'string', 'x-php-name' => 'login']]],
                ['login' => 'user_name: string|null = NULL'],
                [],
            ],
            'superglobal x-php-name' => [
                ['type' => 'object', 'properties' => ['g' => ['type' => 'string', 'x-php-name' => 'GLOBALS']]],
                ['g' => 'g: string|null = NULL'],
                ["error {$at}/properties/g/x-php-name: \"x-php-name\" must be a PHP identifier other than \"this\" and the superglobals."],
            ],
            'invalid x-php-name' => [
                ['type' => 'object', 'properties' => ['user_name' => ['type' => 'string', 'x-php-name' => '1x']]],
                ['userName' => 'user_name: string|null = NULL'],
                ["error {$at}/properties/user_name/x-php-name: \"x-php-name\" must be a PHP identifier other than \"this\" and the superglobals."],
            ],
            'no usable name' => [
                ['type' => 'object', 'properties' => ['§§§' => ['type' => 'string'], 'id' => ['type' => 'string']]],
                ['id' => 'id: string|null = NULL'],
                ["warning {$at}/properties/§§§: Property name \"§§§\" has no usable characters, so the class leaves it out; set \"x-php-name\" to keep it, in the document or \"propertyNames\" of the source."],
            ],
            'names only their signs tell apart' => [
                ['type' => 'object', 'properties' => ['+1' => ['type' => 'integer'], '-1' => ['type' => 'integer'], '*' => ['type' => 'string'], '2' => ['type' => 'string']]],
                ['plus1' => '+1: int|null = NULL', 'minus1' => '-1: int|null = NULL', 'asterisk' => '*: string|null = NULL', '_2' => '2: string|null = NULL'],
                [],
            ],
            'names only signs within tell apart' => [
                ['type' => 'object', 'properties' => ['utc+1' => ['type' => 'integer'], 'utc-1' => ['type' => 'integer'], '1' => ['type' => 'string'], '+1' => ['type' => 'string']]],
                ['utcPlus1' => 'utc+1: int|null = NULL', 'utcMinus1' => 'utc-1: int|null = NULL', '_1' => '1: string|null = NULL', 'plus1' => '+1: string|null = NULL'],
                [],
            ],
            'a spelled name that meets another' => [
                ['type' => 'object', 'properties' => ['+1' => ['type' => 'integer'], '-1' => ['type' => 'integer'], 'plus1' => ['type' => 'integer'], '*' => ['type' => 'string']]],
                ['_1' => '+1: int|null = NULL', 'minus1' => '-1: int|null = NULL', 'plus1' => 'plus1: int|null = NULL', 'asterisk' => '*: string|null = NULL'],
                [],
            ],
            'a sign alone keeps its name' => [
                ['type' => 'object', 'properties' => ['+1' => ['type' => 'integer'], 'x' => ['type' => 'integer', 'x-php-name' => '_1']]],
                ['_1' => '+1: int|null = NULL'],
                ["error {$at}/properties/x: Property \"x\" becomes \$_1, which \"+1\" already uses; set \"x-php-name\" on one of them, in the document or \"propertyNames\" of the source."],
            ],
            'skipped property' => [
                ['type' => 'object', 'properties' => ['secret' => ['type' => 'string', 'x-php-skip' => true], 'id' => ['type' => 'string']]],
                ['id' => 'id: string|null = NULL'],
                [],
            ],
            'x-php-skip not a boolean' => [
                ['type' => 'object', 'properties' => ['id' => ['type' => 'string', 'x-php-skip' => 'yes']]],
                ['id' => 'id: string|null = NULL'],
                ["error {$at}/properties/id/x-php-skip: \"x-php-skip\" must be true or false."],
            ],
            'items are checked too' => [
                ['type' => 'object', 'properties' => ['tags' => ['type' => 'array', 'items' => ['type' => 'string', 'x-php-nmae' => 1, 'x-php-name' => 'x']]]],
                ['tags' => 'tags: list<string>|null = NULL'],
                [
                    "error {$at}/properties/tags/items/x-php-nmae: Unknown extension \"x-php-nmae\"; known: x-php-class-name, x-php-name, x-php-type, x-dto-mutable, x-php-all-of, x-php-skip, x-php-attributes, x-php-read-write, x-enum-descriptions, x-enum-varnames.",
                    "warning {$at}/properties/tags/items/x-php-name: \"x-php-name\" has no effect here.",
                ],
            ],
            'required but skipped' => [
                ['type' => 'object', 'required' => ['secret'], 'properties' => ['secret' => ['type' => 'string', 'x-php-skip' => true], 'id' => ['type' => 'string']]],
                ['id' => 'id: string|null = NULL'],
                ["warning {$at}/properties/secret/x-php-skip: Property \"secret\" is required but excluded by \"x-php-skip\"."],
            ],
            'additional properties' => [
                ['type' => 'object', 'properties' => ['id' => ['type' => 'string']], 'additionalProperties' => ['type' => 'integer']],
                ['id' => 'id: string|null = NULL', 'additionalProperties' => "additionalProperties: array<array-key, int> = array (\n)"],
                [],
            ],
            'additional properties allowed without a schema' => [
                ['type' => 'object', 'properties' => ['id' => ['type' => 'string']], 'additionalProperties' => true],
                ['id' => 'id: string|null = NULL'],
                [],
            ],
            'additional properties clash with a property' => [
                ['type' => 'object', 'properties' => ['additionalProperties' => ['type' => 'string']], 'additionalProperties' => ['type' => 'integer']],
                ['additionalProperties' => 'additionalProperties: string|null = NULL'],
                ["error {$at}/additionalProperties: The class already has a property \$additionalProperties; rename it with \"x-php-name\", in the document or \"propertyNames\" of the source."],
            ],
            'default for an untyped property' => [
                ['type' => 'object', 'properties' => ['any' => ['default' => 5]]],
                ['any' => 'any: mixed = 5'],
                [],
            ],
            'default for a date' => [
                ['type' => 'object', 'properties' => ['at' => ['type' => 'string', 'format' => 'date-time', 'default' => '2020-01-01T00:00:00Z']]],
                ['at' => 'at: DateTimeImmutable|null = NULL'],
                ["warning {$at}/properties/at/default: A default for DateTimeImmutable cannot be a PHP constant expression; null is used instead."],
            ],
            'default for a list of dates' => [
                ['type' => 'object', 'properties' => ['days' => ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'date'], 'default' => ['2020-01-01']]]],
                ['days' => 'days: list<DateTimeImmutable>|null = NULL'],
                ["warning {$at}/properties/days/default: A default for DateTimeImmutable cannot be a PHP constant expression; null is used instead."],
            ],
            'empty default for a list of dates' => [
                ['type' => 'object', 'properties' => ['days' => ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'date'], 'default' => []]]],
                ['days' => 'days: list<DateTimeImmutable>|null = array (
)'],
                [],
            ],
            'default for a list of nullable dates' => [
                ['type' => 'object', 'properties' => ['days' => ['type' => 'array', 'items' => ['type' => ['string', 'null'], 'format' => 'date'], 'default' => ['2020-01-01']]]],
                ['days' => 'days: list<DateTimeImmutable|null>|null = NULL'],
                ["warning {$at}/properties/days/default: A default for DateTimeImmutable cannot be a PHP constant expression; null is used instead."],
            ],
            'empty default for a map' => [
                ['type' => 'object', 'properties' => ['meta' => ['type' => 'object', 'default' => []]]],
                ['meta' => 'meta: array<array-key, mixed>|null = NULL'],
                ["warning {$at}/properties/meta/default: A default for a map is not generated, because JSON object keys do not reliably survive as PHP array keys; null is used instead."],
            ],
            'empty default for a class' => [
                ['type' => 'object', 'properties' => ['money' => ['x-php-type' => 'App\\Money', 'default' => []]]],
                ['money' => "money: App\\Money|null = array (\n)"],
                [],
            ],
            'default for a class' => [
                ['type' => 'object', 'properties' => ['money' => ['x-php-type' => 'App\\Money', 'default' => ['amount' => 1]]]],
                ['money' => 'money: App\\Money|null = NULL'],
                ["warning {$at}/properties/money/default: A default for App\\Money cannot be a PHP constant expression; null is used instead."],
            ],
            'empty default for a list of classes' => [
                ['type' => 'object', 'properties' => ['money' => ['type' => 'array', 'items' => ['x-php-type' => 'App\\Money'], 'default' => [[]]]]],
                ['money' => 'money: list<App\\Money>|null = NULL'],
                ["warning {$at}/properties/money/default: A default for App\\Money cannot be a PHP constant expression; null is used instead."],
            ],
            'empty default for a list of maps' => [
                ['type' => 'object', 'properties' => ['metas' => ['type' => 'array', 'items' => ['type' => 'object'], 'default' => []]]],
                ['metas' => 'metas: list<array<array-key, mixed>>|null = array (
)'],
                [],
            ],
            'default for a list of maps' => [
                ['type' => 'object', 'properties' => ['metas' => ['type' => 'array', 'items' => ['type' => 'object'], 'default' => [['a' => 1]]]]],
                ['metas' => 'metas: list<array<array-key, mixed>>|null = NULL'],
                ["warning {$at}/properties/metas/default: A default for a map is not generated, because JSON object keys do not reliably survive as PHP array keys; null is used instead."],
            ],
            'default for a map' => [
                ['type' => 'object', 'properties' => ['meta' => ['type' => 'object', 'default' => ['a' => 1]]]],
                ['meta' => 'meta: array<array-key, mixed>|null = NULL'],
                ["warning {$at}/properties/meta/default: A default for a map is not generated, because JSON object keys do not reliably survive as PHP array keys; null is used instead."],
            ],
            'infinite default' => [
                ['type' => 'object', 'properties' => ['ratio' => ['type' => 'number', 'default' => INF]]],
                ['ratio' => 'ratio: float|null = NULL'],
                ["error {$at}/properties/ratio/default: A default must be a finite number; INF and NAN have no PHP literal."],
            ],
            'NaN inside a list default' => [
                ['type' => 'object', 'properties' => ['values' => ['type' => 'array', 'items' => ['type' => 'number'], 'default' => [1.0, NAN]]]],
                ['values' => 'values: list<float>|null = NULL'],
                ["error {$at}/properties/values/default: A default must be a finite number; INF and NAN have no PHP literal."],
            ],
            'unknown extension' => [
                ['type' => 'object', 'properties' => ['id' => ['type' => 'string', 'x-php-nmae' => 'x']]],
                ['id' => 'id: string|null = NULL'],
                [
                    "error {$at}/properties/id/x-php-nmae: Unknown extension \"x-php-nmae\"; known: x-php-class-name, x-php-name, x-php-type, x-dto-mutable, x-php-all-of, x-php-skip, x-php-attributes, x-php-read-write, x-enum-descriptions, x-enum-varnames.",
                ],
            ],
            'misplaced extension' => [
                ['type' => 'object', 'properties' => ['id' => ['type' => 'string', 'x-php-all-of' => 'merge']]],
                ['id' => 'id: string|null = NULL'],
                [
                    "warning {$at}/properties/id/x-php-all-of: \"x-php-all-of\" has no effect here.",
                ],
            ],
            'foreign extensions are ignored' => [
                ['type' => 'object', 'properties' => ['id' => ['type' => 'string', 'x-internal' => 1, 'x-dtoish' => 1, 'x-phpstorm' => 1, 'x-php-zzz' => 1]]],
                ['id' => 'id: string|null = NULL'],
                ["error {$at}/properties/id/x-php-zzz: Unknown extension \"x-php-zzz\"; known: x-php-class-name, x-php-name, x-php-type, x-dto-mutable, x-php-all-of, x-php-skip, x-php-attributes, x-php-read-write, x-enum-descriptions, x-enum-varnames."],
            ],
            'x-dto-mutable not a boolean' => [
                ['type' => 'object', 'x-dto-mutable' => 'yes', 'properties' => ['id' => ['type' => 'string']]],
                ['id' => 'id: string|null = NULL'],
                ["error {$at}/x-dto-mutable: \"x-dto-mutable\" must be true or false."],
            ],
        ];
    }

    public function testAppliesXDtoMutable(): void
    {
        [$class] = $this->build(['type' => 'object', 'x-dto-mutable' => true, 'properties' => ['id' => ['type' => 'string']]]);

        self::assertSame('mutable', $class->mutability()->value());
    }

    public function testKeepsTheTargetMutabilityWhenXDtoMutableIsNotABoolean(): void
    {
        [$class, $messages] = $this->build(['type' => 'object', 'x-dto-mutable' => 'yes', 'properties' => ['id' => ['type' => 'string']]]);

        self::assertSame('immutable', $class->mutability()->value());
        self::assertSame(['error ' . self::AT . '/x-dto-mutable: "x-dto-mutable" must be true or false.'], $messages);
    }

    public function testRejectsAnImmutableOverrideTheTargetCannotExpress(): void
    {
        [$class, $messages] = $this->build(
            ['type' => 'object', 'x-dto-mutable' => false, 'properties' => ['id' => ['type' => 'string']]],
            new TargetProfile(
                PhpVersion::fromString('7.4'),
                MetadataMode::from(MetadataMode::NONE),
                Mutability::from(Mutability::MUTABLE),
                AccessorStyle::from(AccessorStyle::PUBLIC_PROPERTIES),
                DateTimeClass::from(DateTimeClass::IMMUTABLE),
                true,
            ),
        );

        self::assertSame('mutable', $class->mutability()->value());
        self::assertSame(
            ['error ' . self::AT . '/x-dto-mutable: Immutable DTOs with public properties requires readonly-properties (PHP 8.1+), but the target is PHP 7.4.'],
            $messages,
        );
    }

    public function testLeavesNoEmptyObjectDefaultForAClassBeforePhp81(): void
    {
        [$class, $messages] = $this->build(
            ['type' => 'object', 'properties' => ['money' => ['x-php-type' => 'App\\Money', 'default' => []]]],
            new TargetProfile(
                PhpVersion::fromString('8.0'),
                MetadataMode::from(MetadataMode::NONE),
                Mutability::from(Mutability::MUTABLE),
                AccessorStyle::from(AccessorStyle::AUTO),
                DateTimeClass::from(DateTimeClass::IMMUTABLE),
                true,
            ),
        );

        self::assertEquals(new DefaultValue(null), $this->property($class, 'money')->default());
        self::assertSame(['warning ' . self::AT . '/properties/money/default: A default for App\\Money cannot be a PHP constant expression; null is used instead.'], $messages);
    }

    public function testRecognisesSkippedSchemas(): void
    {
        $graph = GraphFixture::load([
            'A' => ['type' => 'object', 'properties' => ['x' => []], 'x-php-skip' => true],
            'B' => ['type' => 'object', 'properties' => ['x' => []]],
        ]);
        $diagnostics = new Diagnostics();

        self::assertTrue(ClassBuilder::isSkipped($graph->all()[0]->schema(), $diagnostics));
        self::assertFalse(ClassBuilder::isSkipped($graph->all()[1]->schema(), $diagnostics));
        self::assertSame([], $diagnostics->all());
    }

    public function testKeepsThePropertiesOfAView(): void
    {
        $schema = ['type' => 'object', 'required' => ['id', 'password', 'name'], 'properties' => [
            'id' => ['type' => 'integer', 'readOnly' => true],
            'password' => ['type' => 'string', 'writeOnly' => true],
            'name' => ['type' => 'string'],
            'both' => ['type' => 'string', 'readOnly' => true, 'writeOnly' => true],
        ], 'additionalProperties' => ['type' => 'string', 'readOnly' => true]];

        [$read, $readMessages] = $this->build($schema, null, 'read');
        [$write] = $this->build($schema, null, 'write');
        [$all] = $this->build($schema);

        self::assertSame(['id', 'name', 'both', 'additionalProperties'], $this->wireNames($read));
        self::assertSame(['password', 'name', 'both'], $this->wireNames($write));
        self::assertSame(['id', 'password', 'name', 'both', 'additionalProperties'], $this->wireNames($all));
        self::assertSame(['warning /project/api/openapi.yaml#/components/schemas/User/properties/both: "readOnly" and "writeOnly" are both true; the property is in both views.'], $readMessages);
    }

    public function testExcludesAPropertyOfAnotherViewFromEveryMergedMember(): void
    {
        $schema = ['allOf' => [
            ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]],
            ['type' => 'object', 'properties' => ['id' => ['type' => 'integer', 'readOnly' => true]]],
        ]];

        [$write] = $this->build($schema, null, 'write');

        self::assertSame([], $this->wireNames($write));
    }

    /**
     * @return list<string>
     */
    private function wireNames(ClassModel $class): array
    {
        return array_map(static fn (PropertyModel $property): string => $property->wireName(), $class->properties());
    }

    /**
     * @param array<array-key, mixed> $schema
     * @param 'read'|'write'|null $view
     *
     * @return array{ClassModel, list<string>}
     */
    private function build(array $schema, ?TargetProfile $target = null, ?string $view = null): array
    {
        $graph = GraphFixture::load(['User' => $schema]);
        $target ??= new TargetProfile(
            PhpVersion::fromString('8.2'),
            MetadataMode::from(MetadataMode::NONE),
            Mutability::from(Mutability::IMMUTABLE),
            AccessorStyle::from(AccessorStyle::AUTO),
            DateTimeClass::from(DateTimeClass::IMMUTABLE),
            true,
        );
        $diagnostics = new Diagnostics();
        $builder = new ClassBuilder(new NameResolver(), new TypeMapper($graph, new Declarations(), $target, []), $target, [], $view === null ? null : new PropertyView(Direction::from($view), new Directions($graph, $diagnostics), new ReadWriteMode(ReadWriteModels::from(ReadWriteModels::SPLIT))));
        $class = $builder->build(ClassName::fromFqcn('App\Dto\User'), $graph->all()[0]->schema(), $diagnostics);

        return [$class, array_map(static fn (Diagnostic $d): string => $d->toString(), $diagnostics->all())];
    }

    private function property(ClassModel $class, string $name): PropertyModel
    {
        $property = $class->property($name);
        self::assertNotNull($property);

        return $property;
    }

    /**
     * @return array<string, string>
     */
    private function summary(ClassModel $class): array
    {
        $summary = [];
        foreach ($class->properties() as $property) {
            $default = $property->default();
            $summary[$property->name()] = sprintf(
                '%s: %s%s',
                $property->wireName(),
                $property->type()->describe(),
                $default instanceof DefaultValue ? ' = ' . var_export($default->value(), true) : '',
            );
        }

        return $summary;
    }

    /**
     * @dataProvider mismatchedDefaults
     *
     * @param array<array-key, mixed> $property
     */
    public function testReplacesDefaultsThatDoNotMatchTheTypeWithNull(array $property, string $value, string $type): void
    {
        [$class, $messages] = $this->build(['type' => 'object', 'properties' => ['v' => $property]]);

        self::assertSame(['warning ' . self::AT . "/properties/v/default: Default {$value} does not match {$type}; null is used instead."], $messages);
        self::assertEquals(new DefaultValue(null), $this->property($class, 'v')->default());
    }

    /**
     * @dataProvider defaultsWrittenAsStrings
     *
     * @param array<array-key, mixed> $property
     * @param bool|int|float|string $expected
     */
    public function testReadsADefaultWrittenAsAString(array $property, $expected): void
    {
        [$class, $messages] = $this->build(['type' => 'object', 'properties' => ['v' => $property]]);

        self::assertSame([], $messages);
        $default = $this->property($class, 'v')->default();
        self::assertNotNull($default);
        self::assertSame($expected, $default->value());
    }

    /**
     * @return array<string, array{array<array-key, mixed>, bool|int|float|string}>
     */
    public static function defaultsWrittenAsStrings(): array
    {
        return [
            'true' => [['type' => 'boolean', 'default' => 'true'], true],
            'false' => [['type' => 'boolean', 'default' => 'false'], false],
            'zero' => [['type' => 'integer', 'default' => '0'], 0],
            'whole float of a number of whole numbers' => [['type' => 'number', 'format' => 'int32', 'default' => 1.0], 1],
            'negative whole float of 2^53' => [['type' => 'number', 'format' => 'int64', 'default' => -(2.0 ** 53)], -(2 ** 53)],
            'numeric string' => [['type' => 'string', 'format' => 'decimal', 'default' => '-1.5e3'], '-1.5e3'],
            'digits with leading zeros' => [['type' => 'string', 'format' => 'digits', 'default' => '0012'], '0012'],
            'whole float of 2^53' => [['type' => 'number', 'format' => 'int64', 'default' => 2.0 ** 53], 2 ** 53],
            'large whole float' => [['type' => 'number', 'format' => 'int64', 'default' => 2.0 ** 54], 2 ** 54],
            'lowest whole float of int' => [['type' => 'number', 'format' => 'int64', 'default' => (float) PHP_INT_MIN], PHP_INT_MIN],
            'negative zero' => [['type' => 'number', 'format' => 'int32', 'default' => -0.0], 0],
            'exponent' => [['type' => 'number', 'default' => '1e3'], 1000.0],
            'int' => [['type' => 'integer', 'default' => '-42'], -42],
            'float' => [['type' => 'number', 'default' => '1.5'], 1.5],
            'whole float' => [['type' => 'number', 'default' => '2'], 2.0],
            'nullable bool' => [['type' => ['boolean', 'null'], 'default' => 'true'], true],
            'string' => [['type' => 'string', 'default' => 'true'], 'true'],
        ];
    }

    /**
     * @return array<string, array{array<array-key, mixed>, string, string}>
     */
    public static function mismatchedDefaults(): array
    {
        return [
            'string for int' => [['type' => 'integer', 'default' => 'abc'], '"abc"', 'int'],
            'fraction for int' => [['type' => 'integer', 'default' => 1.5], '1.5', 'int'],
            'int for string' => [['type' => 'string', 'default' => 5], '5', 'string'],
            'string for bool' => [['type' => 'boolean', 'default' => 'yes'], '"yes"', 'bool'],
            'string for float' => [['type' => 'number', 'default' => 'one'], '"one"', 'float'],
            'fraction string for int' => [['type' => 'integer', 'default' => '1.5'], '"1.5"', 'int'],
            'fraction for a number of whole numbers' => [['type' => 'number', 'format' => 'int32', 'default' => 1.5], '1.5', 'int'],
            'huge whole float for int' => [['type' => 'number', 'format' => 'int64', 'default' => 1.0e19], '1.0e+19', 'int'],
            'word for a numeric string' => [['type' => 'string', 'format' => 'int64', 'default' => 'abc'], '"abc"', 'numeric-string'],
            'numeric string with a space' => [['type' => 'string', 'format' => 'decimal', 'default' => ' 1'], '" 1"', 'numeric-string'],
            'numeric string with a newline' => [['type' => 'string', 'format' => 'decimal', 'default' => "1\n"], '"1\\n"', 'numeric-string'],
            'numeric string with a plus' => [['type' => 'string', 'format' => 'decimal', 'default' => '+1'], '"+1"', 'numeric-string'],
            'numeric string with a trailing point' => [['type' => 'string', 'format' => 'decimal', 'default' => '1.'], '"1."', 'numeric-string'],
            'numeric string without leading digits' => [['type' => 'string', 'format' => 'decimal', 'default' => '.5'], '".5"', 'numeric-string'],
            'negative whole float past int' => [['type' => 'number', 'format' => 'int64', 'default' => -1.0e19], '-1.0e+19', 'int'],
            'whole float at the top of int' => [['type' => 'number', 'format' => 'int64', 'default' => (float) PHP_INT_MAX], '9.223372036854776e+18', 'int'],
            'empty numeric string' => [['type' => 'string', 'format' => 'decimal', 'default' => ''], '""', 'numeric-string'],
            'bool in capitals' => [['type' => 'boolean', 'default' => 'FALSE'], '"FALSE"', 'bool'],
            'int with spaces' => [['type' => 'integer', 'default' => ' 42'], '" 42"', 'int'],
            'int with a leading zero' => [['type' => 'integer', 'default' => '010'], '"010"', 'int'],
            'int with a newline' => [['type' => 'integer', 'default' => "42\n"], '"42\\n"', 'int'],
            'float with a newline' => [['type' => 'number', 'default' => "1.5\n"], '"1.5\\n"', 'float'],
            'int with a plus' => [['type' => 'integer', 'default' => '+1'], '"+1"', 'int'],
            'int with an exponent' => [['type' => 'integer', 'default' => '1e3'], '"1e3"', 'int'],
            'int overflow' => [['type' => 'integer', 'default' => '99999999999999999999'], '"99999999999999999999"', 'int'],
            'float with a trailing space' => [['type' => 'number', 'default' => '1.5 '], '"1.5 "', 'float'],
            'float with a leading zero' => [['type' => 'number', 'default' => '010'], '"010"', 'float'],
            'float without digits after the point' => [['type' => 'number', 'default' => '1.'], '"1."', 'float'],
            'string out of range' => [['type' => 'integer', 'minimum' => 0, 'default' => '-1'], '"-1"', 'non-negative-int'],
            'infinite string for float' => [['type' => 'number', 'default' => '1e999'], '"1e999"', 'float'],
            'bool for union' => [['type' => ['integer', 'string'], 'default' => true], 'true', 'int|string'],
            'empty for non-empty' => [['type' => 'string', 'minLength' => 1, 'default' => ''], '""', 'non-empty-string'],
            'below the range' => [['type' => 'integer', 'minimum' => 5, 'default' => 4], '4', 'int<5, max>'],
            'zero for positive' => [['type' => 'integer', 'minimum' => 1, 'default' => 0], '0', 'positive-int'],
            'negative for non-negative' => [['type' => 'integer', 'minimum' => 0, 'default' => -1], '-1', 'non-negative-int'],
            'above the range' => [['type' => 'integer', 'maximum' => 5, 'default' => 6], '6', 'int<min, 5>'],
            'object for list' => [['type' => 'array', 'items' => ['type' => 'integer'], 'default' => ['a' => 1]], '{"a":1}', 'list<int>'],
            'wrong list item' => [['type' => 'array', 'items' => ['type' => 'integer'], 'default' => [1, 'x']], '[1,"x"]', 'list<int>'],
            'path for int' => [['type' => 'integer', 'default' => "a/\u{00FC}"], "\"a/\u{00FC}\"", 'int'],
            'whole float for int' => [['type' => 'integer', 'default' => 2.0], '2.0', 'int'],
            'missing item in a list' => [['type' => 'array', 'items' => ['type' => 'integer'], 'default' => [1, null]], '[1,null]', 'list<int>'],
            'wrong nullable item' => [['type' => 'array', 'items' => ['type' => ['integer', 'null']], 'default' => ['x']], '["x"]', 'list<int|null>'],
            'invalid utf-8 for int' => [['type' => 'integer', 'default' => "\xFF"], "\"\u{FFFD}\"", 'int'],
            'scalar for a list of classes' => [['type' => 'array', 'items' => ['x-php-type' => 'App\\Money'], 'default' => 'x'], '"x"', 'list<App\\Money>'],
            'object for a list of classes' => [['type' => 'array', 'items' => ['x-php-type' => 'App\\Money'], 'default' => ['a' => 1]], '{"a":1}', 'list<App\\Money>'],
            'scalar for list' => [['type' => 'array', 'default' => 'x'], '"x"', 'list<mixed>'],
        ];
    }

    /**
     * @dataProvider matchingDefaults
     *
     * @param array<array-key, mixed> $property
     * @param int|float|string|list<int|null>|null $expected
     */
    public function testKeepsDefaultsThatMatchTheType(array $property, $expected): void
    {
        [$class, $messages] = $this->build(['type' => 'object', 'properties' => ['v' => $property]]);

        self::assertSame([], $messages);
        $default = $this->property($class, 'v')->default();
        self::assertNotNull($default);
        self::assertSame($expected, $default->value());
    }

    /**
     * @return array<string, array{array<array-key, mixed>, int|float|string|list<int|null>|null}>
     */
    public static function matchingDefaults(): array
    {
        return [
            'int for float' => [['type' => 'number', 'default' => 5], 5],
            'float' => [['type' => 'number', 'default' => 1.5], 1.5],
            'non-empty string' => [['type' => 'string', 'minLength' => 1, 'default' => 'x'], 'x'],
            'string in a union' => [['type' => ['integer', 'string'], 'default' => 'x'], 'x'],
            'lower bound' => [['type' => 'integer', 'minimum' => 5, 'maximum' => 9, 'default' => 5], 5],
            'upper bound' => [['type' => 'integer', 'minimum' => 5, 'maximum' => 9, 'default' => 9], 9],
            'positive' => [['type' => 'integer', 'minimum' => 1, 'default' => 1], 1],
            'non-negative' => [['type' => 'integer', 'minimum' => 0, 'default' => 0], 0],
            'no lower bound' => [['type' => 'integer', 'maximum' => 5, 'default' => -3], -3],
            'no upper bound' => [['type' => 'integer', 'minimum' => 5, 'default' => 100], 100],
            'nullable items' => [['type' => 'array', 'items' => ['type' => ['integer', 'null']], 'default' => [1, null]], [1, null]],
            'list of ints' => [['type' => 'array', 'items' => ['type' => 'integer'], 'default' => [1, 2]], [1, 2]],
            'empty list' => [['type' => 'array', 'default' => []], []],
            'explicit null' => [['type' => 'integer', 'default' => null], null],
        ];
    }
}
