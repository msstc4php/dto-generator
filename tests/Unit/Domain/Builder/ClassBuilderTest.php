<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Builder\ClassBuilder;
use MSSTC4PHP\DtoGenerator\Domain\Builder\NameResolver;
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
use MSSTC4PHP\DtoGenerator\Domain\Target\TargetProfile;
use MSSTC4PHP\DtoGenerator\Tests\Support\GraphFixture;
use PHPUnit\Framework\TestCase;

final class ClassBuilderTest extends TestCase
{
    private const AT = '/project/api/openapi.yaml#/components/schemas/User';

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
                ['userName' => 'user_name: string|null = NULL', 'id' => 'id: string|null = NULL'],
                ["error {$at}/properties/userName: Property \"userName\" becomes \$userName, which \"user_name\" already uses; set \"x-php-name\" on one of them."],
            ],
            'names colliding by case' => [
                ['type' => 'object', 'properties' => ['URL' => ['type' => 'string'], 'url' => ['type' => 'string']]],
                ['url' => 'URL: string|null = NULL'],
                ["error {$at}/properties/url: Property \"url\" becomes \$url, which \"URL\" already uses; set \"x-php-name\" on one of them."],
            ],
            'x-php-name' => [
                ['type' => 'object', 'properties' => ['user_name' => ['type' => 'string', 'x-php-name' => 'login']]],
                ['login' => 'user_name: string|null = NULL'],
                [],
            ],
            'invalid x-php-name' => [
                ['type' => 'object', 'properties' => ['user_name' => ['type' => 'string', 'x-php-name' => '1x']]],
                ['userName' => 'user_name: string|null = NULL'],
                ["error {$at}/properties/user_name/x-php-name: \"x-php-name\" must be a PHP identifier other than \"this\"."],
            ],
            'no usable name' => [
                ['type' => 'object', 'properties' => ['---' => ['type' => 'string'], 'id' => ['type' => 'string']]],
                ['id' => 'id: string|null = NULL'],
                ["error {$at}/properties/---: Property name \"---\" has no usable characters; set \"x-php-name\"."],
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
            'default for a map' => [
                ['type' => 'object', 'properties' => ['meta' => ['type' => 'object', 'default' => ['a' => 1]]]],
                ['meta' => 'meta: array<array-key, mixed>|null = NULL'],
                ["warning {$at}/properties/meta/default: A default for array<array-key, mixed> cannot be a PHP constant expression; null is used instead."],
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
                ['type' => 'object', 'x-dto-mutible' => true, 'properties' => ['id' => ['type' => 'string', 'x-php-nmae' => 'x']]],
                ['id' => 'id: string|null = NULL'],
                [
                    "error {$at}/x-dto-mutible: Unknown extension \"x-dto-mutible\"; known: x-php-class-name, x-php-name, x-php-type, x-dto-mutable, x-php-all-of, x-php-skip, x-php-attributes, x-enum-descriptions.",
                    "error {$at}/properties/id/x-php-nmae: Unknown extension \"x-php-nmae\"; known: x-php-class-name, x-php-name, x-php-type, x-dto-mutable, x-php-all-of, x-php-skip, x-php-attributes, x-enum-descriptions.",
                ],
            ],
            'misplaced extension' => [
                ['type' => 'object', 'x-php-name' => 'x', 'properties' => ['id' => ['type' => 'string', 'x-dto-mutable' => true]]],
                ['id' => 'id: string|null = NULL'],
                [
                    "warning {$at}/x-php-name: \"x-php-name\" has no effect here.",
                    "warning {$at}/properties/id/x-dto-mutable: \"x-dto-mutable\" has no effect here.",
                ],
            ],
            'foreign extensions are ignored' => [
                ['type' => 'object', 'x-audit' => true, 'x-dtoish' => 1, 'x-phpstorm' => 1, 'properties' => ['id' => ['type' => 'string', 'x-internal' => 1, 'x-php-zzz' => 1]]],
                ['id' => 'id: string|null = NULL'],
                ["error {$at}/properties/id/x-php-zzz: Unknown extension \"x-php-zzz\"; known: x-php-class-name, x-php-name, x-php-type, x-dto-mutable, x-php-all-of, x-php-skip, x-php-attributes, x-enum-descriptions."],
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

    /**
     * @param array<array-key, mixed> $schema
     *
     * @return array{ClassModel, list<string>}
     */
    private function build(array $schema, ?TargetProfile $target = null): array
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
        $builder = new ClassBuilder(new NameResolver(), new TypeMapper($graph, [], [], $target, []), $target);
        $diagnostics = new Diagnostics();
        $class = $builder->build(ClassName::fromFqcn('App\Dto\User'), $graph->all()[0], $diagnostics);

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
}
