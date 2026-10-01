<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Builder\SchemaShape;
use MSSTC4PHP\DtoGenerator\Domain\Builder\TypeMapper;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostic;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Model\MixedType;
use MSSTC4PHP\DtoGenerator\Domain\Model\NullableType;
use MSSTC4PHP\DtoGenerator\Domain\Model\ScalarType;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaGraph;
use MSSTC4PHP\DtoGenerator\Domain\Target\AccessorStyle;
use MSSTC4PHP\DtoGenerator\Domain\Target\DateTimeClass;
use MSSTC4PHP\DtoGenerator\Domain\Target\MetadataMode;
use MSSTC4PHP\DtoGenerator\Domain\Target\Mutability;
use MSSTC4PHP\DtoGenerator\Domain\Target\PhpVersion;
use MSSTC4PHP\DtoGenerator\Domain\Target\TargetProfile;
use MSSTC4PHP\DtoGenerator\Tests\Support\GraphFixture;
use PHPUnit\Framework\TestCase;

final class TypeMapperTest extends TestCase
{
    private const TAG = '/project/api/openapi.yaml#/components/schemas/Tag';

    /**
     * @dataProvider types
     *
     * @param array<array-key, mixed> $property
     * @param array<int|string, ClassName> $formats
     */
    public function testMapsSchemasToTypes(array $property, string $expected, array $formats = []): void
    {
        [$type, $messages] = $this->map($property, $formats);

        self::assertSame($expected, $type);
        self::assertSame([], $messages);
    }

    /**
     * @return array<string, array{0: array<array-key, mixed>, 1: string, 2?: array<int|string, ClassName>}>
     */
    public static function types(): array
    {
        return [
            'string' => [['type' => 'string'], 'string'],
            'non-empty string' => [['type' => 'string', 'minLength' => 1], 'non-empty-string'],
            'zero min length' => [['type' => 'string', 'minLength' => 0], 'string'],
            'email stays a string' => [['type' => 'string', 'format' => 'email'], 'string'],
            'date-time' => [['type' => 'string', 'format' => 'date-time'], 'DateTimeImmutable'],
            'date' => [['type' => 'string', 'format' => 'date'], 'DateTimeImmutable'],
            'custom format' => [['type' => 'string', 'format' => 'uuid'], 'App\Uuid', ['uuid' => ClassName::fromFqcn('App\Uuid')]],
            'integer' => [['type' => 'integer', 'format' => 'int64'], 'int'],
            'positive' => [['type' => 'integer', 'minimum' => 1], 'positive-int'],
            'non-negative' => [['type' => 'integer', 'minimum' => 0], 'non-negative-int'],
            'exclusive minimum' => [['type' => 'integer', 'exclusiveMinimum' => 0], 'positive-int'],
            'lower bound' => [['type' => 'integer', 'minimum' => 5], 'int<5, max>'],
            'upper bound' => [['type' => 'integer', 'maximum' => 10], 'int<min, 10>'],
            'exclusive upper bound' => [['type' => 'integer', 'exclusiveMaximum' => 10], 'int<min, 9>'],
            'range' => [['type' => 'integer', 'minimum' => 5, 'maximum' => 10], 'int<5, 10>'],
            'single value range' => [['type' => 'integer', 'minimum' => 5, 'maximum' => 5], 'int<5, 5>'],
            'tighter of two upper bounds' => [['type' => 'integer', 'maximum' => 10, 'exclusiveMaximum' => 5], 'int<min, 4>'],
            'tighter of two lower bounds' => [['type' => 'integer', 'minimum' => 1, 'exclusiveMinimum' => 4], 'int<5, max>'],
            'fractional bound ignored' => [['type' => 'integer', 'minimum' => 1.5], 'int'],
            'number' => [['type' => 'number', 'format' => 'double'], 'float'],
            'boolean' => [['type' => 'boolean'], 'bool'],
            'list' => [['type' => 'array', 'items' => ['type' => 'string']], 'list<string>'],
            'list without items' => [['type' => 'array'], 'list<mixed>'],
            'free-form object' => [['type' => 'object'], 'array<array-key, mixed>'],
            'no type' => [[], 'mixed'],
            'type union' => [['type' => ['string', 'integer']], 'string|int'],
            'nullable' => [['type' => ['string', 'null']], 'string|null'],
            'nullable list' => [['type' => ['array', 'null'], 'items' => ['type' => 'integer']], 'list<int>|null'],
            'only null' => [['type' => 'null'], 'mixed'],
            'class reference' => [['$ref' => '#/components/schemas/Tag'], 'App\Dto\Tag'],
            'list of classes' => [['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Tag']], 'list<App\Dto\Tag>'],
            'alias is inlined' => [['$ref' => '#/components/schemas/Email'], 'string'],
            'nullable alias' => [['$ref' => '#/components/schemas/MaybeCount'], 'int|null'],
            'x-php-type' => [['type' => 'string', 'x-php-type' => '\Symfony\Component\Uid\Uuid'], 'Symfony\Component\Uid\Uuid'],
            'nullable x-php-type' => [['type' => ['string', 'null'], 'x-php-type' => 'App\Uuid'], 'App\Uuid|null'],
        ];
    }

    public function testUsesTheConfiguredDateTimeClass(): void
    {
        [$type] = $this->map(['type' => 'string', 'format' => 'date-time'], [], '8.2', DateTimeClass::MUTABLE);

        self::assertSame('DateTime', $type);
    }

    /**
     * @dataProvider problems
     *
     * @param array<array-key, mixed> $property
     * @param list<string> $expected
     */
    public function testReportsWhatItCannotMap(array $property, string $type, array $expected, string $php = '8.2'): void
    {
        [$mapped, $messages] = $this->map($property, [], $php);

        self::assertSame($type, $mapped);
        self::assertSame($expected, $messages);
    }

    /**
     * @return array<string, array{0: array<array-key, mixed>, 1: string, 2: list<string>, 3?: string}>
     */
    public static function problems(): array
    {
        $at = '/project/api/openapi.yaml#/components/schemas/Holder/properties/value';

        return [
            'unknown string format' => [['type' => 'string', 'format' => 'color'], 'string', ["warning {$at}/format: Unknown string format \"color\"; the property stays a string."]],
            'unknown integer format' => [['type' => 'integer', 'format' => 'int128'], 'int', ["warning {$at}/format: Unknown integer format \"int128\"; the property stays an int."]],
            'unknown number format' => [['type' => 'number', 'format' => 'decimal'], 'float', ["warning {$at}/format: Unknown number format \"decimal\"; the property stays a float."]],
            'exclusive minimum at the top' => [['type' => 'integer', 'exclusiveMinimum' => PHP_INT_MAX], 'int', ["warning {$at}/exclusiveMinimum: \"exclusiveMinimum\" leaves no integer above it, so it is ignored."]],
            'exclusive maximum at the bottom' => [['type' => 'integer', 'exclusiveMaximum' => PHP_INT_MIN], 'int', ["warning {$at}/exclusiveMaximum: \"exclusiveMaximum\" leaves no integer below it, so it is ignored."]],
            'empty range' => [['type' => 'integer', 'minimum' => 10, 'maximum' => 5], 'int', ["warning {$at}: The minimum is greater than the maximum, so no range is applied."]],
            'enum' => [['type' => 'string', 'enum' => ['a']], 'mixed', ["error {$at}: \"enum\" is not supported yet; enums, composition and inline objects arrive in a later version."]],
            'oneOf' => [['oneOf' => [['type' => 'string']]], 'mixed', ["error {$at}: \"oneOf\" is not supported yet; enums, composition and inline objects arrive in a later version."]],
            'allOf' => [['allOf' => [['type' => 'string']]], 'mixed', ["error {$at}: \"allOf\" is not supported yet; enums, composition and inline objects arrive in a later version."]],
            'discriminator alone' => [['discriminator' => ['propertyName' => 'kind']], 'mixed', ["error {$at}: \"discriminator\" is not supported yet; enums, composition and inline objects arrive in a later version."]],
            'map schema' => [['type' => 'object', 'additionalProperties' => ['type' => 'string']], 'mixed', ["error {$at}: \"additionalProperties\" is not supported yet; enums, composition and inline objects arrive in a later version."]],
            'inline object' => [['type' => 'object', 'properties' => ['a' => []]], 'mixed', ["error {$at}: Inline object schemas are not supported yet; move it to components/schemas and use \$ref."]],
            'enum behind an alias' => [['$ref' => '#/components/schemas/Currency'], 'mixed', ['error /project/api/openapi.yaml#/components/schemas/Currency: "enum" is not supported yet; enums, composition and inline objects arrive in a later version.']],
            'alias loop' => [['$ref' => '#/components/schemas/LoopA'], 'mixed', ['error /project/api/openapi.yaml#/components/schemas/LoopA: The $ref chain loops back to itself without reaching an object schema.']],
            'skipped target' => [['$ref' => '#/components/schemas/Hidden'], 'mixed', ["warning {$at}: \$ref points to a schema excluded by \"x-php-skip\"."]],
            'x-php-type not a string' => [['x-php-type' => 5], 'mixed', ["error {$at}/x-php-type: \"x-php-type\" must be a class name."]],
            'x-php-type not a class' => [['x-php-type' => 'Not A Class'], 'mixed', ["error {$at}/x-php-type: \"Not A Class\" is not a valid class name: segment \"Not A Class\" is not a PHP identifier."]],
            'x-php-type reserved segment on 7.4' => [['x-php-type' => 'App\List\Uuid'], 'mixed', ["error {$at}/x-php-type: Namespace \"App\\List\" contains the reserved word \"List\", which PHP 7.4 cannot parse in a namespace (allowed from PHP 8.0)."], '7.4'],
        ];
    }

    public function testNullableNeverWrapsMixedOrNullable(): void
    {
        $nullable = new NullableType(ScalarType::string());

        self::assertInstanceOf(MixedType::class, TypeMapper::nullable(new MixedType()));
        self::assertSame($nullable, TypeMapper::nullable($nullable));
        self::assertSame('int|null', TypeMapper::nullable(ScalarType::int())->describe());
    }

    public function testRecognisesClassShapedSchemas(): void
    {
        $graph = $this->graph(['value' => []]);
        $shapes = [];
        foreach ($graph->all() as $resolved) {
            $shapes[$resolved->name()] = SchemaShape::isClass($resolved->schema());
        }

        self::assertSame(
            ['Holder' => true, 'Tag' => true, 'Email' => false, 'MaybeCount' => false, 'Currency' => false, 'LoopA' => false, 'LoopB' => false, 'Hidden' => true, 'Free' => false, 'RefWithProperties' => false, 'StringWithProperties' => false],
            $shapes,
        );
    }

    /**
     * @param array<array-key, mixed> $property
     * @param array<int|string, ClassName> $formats
     *
     * @return array{string, list<string>}
     */
    private function map(array $property, array $formats = [], string $php = '8.2', string $dateTimeClass = DateTimeClass::IMMUTABLE): array
    {
        $graph = $this->graph($property);
        $mapper = new TypeMapper(
            $graph,
            [self::TAG => ClassName::fromFqcn('App\Dto\Tag')],
            ['/project/api/openapi.yaml#/components/schemas/Hidden' => true],
            new TargetProfile(
                PhpVersion::fromString($php),
                MetadataMode::from(MetadataMode::NONE),
                Mutability::from(Mutability::MUTABLE),
                AccessorStyle::from(AccessorStyle::AUTO),
                DateTimeClass::from($dateTimeClass),
                true,
            ),
            $formats,
        );
        $holder = $graph->all()[0]->schema()->property('value');
        self::assertNotNull($holder);
        $diagnostics = new Diagnostics();
        $type = $mapper->map($holder, $diagnostics);

        return [$type->describe(), array_map(static fn (Diagnostic $d): string => $d->toString(), $diagnostics->all())];
    }

    /**
     * @param array<array-key, mixed> $property
     */
    private function graph(array $property): SchemaGraph
    {
        return GraphFixture::load([
            'Holder' => ['type' => 'object', 'properties' => ['value' => $property]],
            'Tag' => ['type' => 'object', 'properties' => ['label' => ['type' => 'string']]],
            'Email' => ['type' => 'string', 'format' => 'email'],
            'MaybeCount' => ['type' => ['integer', 'null']],
            'Currency' => ['type' => 'string', 'enum' => ['EUR']],
            'LoopA' => ['$ref' => '#/components/schemas/LoopB'],
            'LoopB' => ['$ref' => '#/components/schemas/LoopA'],
            'Hidden' => ['type' => 'object', 'properties' => ['x' => []], 'x-php-skip' => true],
            'Free' => ['type' => 'object'],
            'RefWithProperties' => ['$ref' => '#/components/schemas/Tag', 'properties' => ['a' => []]],
            'StringWithProperties' => ['type' => 'string', 'properties' => ['a' => []]],
        ]);
    }

    public function testAnUnresolvedReferenceIsMixedWithoutAnotherDiagnostic(): void
    {
        $loaded = $this->graph(['$ref' => '#/components/schemas/Tag']);
        $withoutEdges = new SchemaGraph($loaded->all());
        $mapper = new TypeMapper(
            $withoutEdges,
            [],
            [],
            new TargetProfile(
                PhpVersion::fromString('8.2'),
                MetadataMode::from(MetadataMode::NONE),
                Mutability::from(Mutability::MUTABLE),
                AccessorStyle::from(AccessorStyle::AUTO),
                DateTimeClass::from(DateTimeClass::IMMUTABLE),
                true,
            ),
            [],
        );
        $holder = $withoutEdges->all()[0]->schema()->property('value');
        self::assertNotNull($holder);
        $diagnostics = new Diagnostics();

        self::assertSame('mixed', $mapper->map($holder, $diagnostics)->describe());
        self::assertSame([], $diagnostics->all());
    }
}
