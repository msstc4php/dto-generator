<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Builder\Declarations;
use MSSTC4PHP\DtoGenerator\Domain\Builder\SchemaShape;
use MSSTC4PHP\DtoGenerator\Domain\Builder\TypeMapper;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostic;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Model\EnumBacking;
use MSSTC4PHP\DtoGenerator\Domain\Model\EnumType;
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
use MSSTC4PHP\DtoGenerator\Tests\Support\EmitterFixture;
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
            'oneOf of scalars' => [['oneOf' => [['type' => 'string'], ['type' => 'integer']]], 'string|int'],
            'anyOf of classes' => [['anyOf' => [['$ref' => '#/components/schemas/Tag'], ['$ref' => '#/components/schemas/Note']]], 'App\Dto\Tag|App\Dto\Note'],
            'oneOf with a null member' => [['oneOf' => [['$ref' => '#/components/schemas/Tag'], ['type' => 'null']]], 'App\Dto\Tag|null'],
            'oneOf with a nullable member' => [['oneOf' => [['type' => 'string'], ['type' => ['integer', 'null']]]], 'string|int|null'],
            'oneOf of one type twice' => [['oneOf' => [['type' => 'string'], ['type' => 'string', 'format' => 'email']]], 'string'],
            'oneOf with an untyped member' => [['oneOf' => [['type' => 'string'], []]], 'mixed'],
            'inline discriminated union' => [
                ['oneOf' => [['$ref' => '#/components/schemas/Tag'], ['$ref' => '#/components/schemas/Note']], 'discriminator' => ['propertyName' => 'kind']],
                'App\Dto\Tag|App\Dto\Note',
            ],
            'allOf wrapping a reference' => [['allOf' => [['$ref' => '#/components/schemas/Tag']], 'description' => 'The tag.'], 'App\Dto\Tag'],
            'nullable allOf wrapper' => [['allOf' => [['$ref' => '#/components/schemas/Tag']], 'type' => ['object', 'null']], 'App\Dto\Tag|null'],
            'allOf wrapping an alias' => [['allOf' => [['$ref' => '#/components/schemas/Email']]], 'string'],
            'allOf with constraints only' => [['type' => 'integer', 'allOf' => [['minimum' => 1]]], 'int'],
            'allOf of a reference and constraints' => [['allOf' => [['$ref' => '#/components/schemas/Email'], ['maxLength' => 5]]], 'string'],
            'discriminator alone' => [['discriminator' => ['propertyName' => 'kind']], 'mixed'],
            'inline discriminated union with properties' => [
                ['properties' => ['kind' => []], 'oneOf' => [['$ref' => '#/components/schemas/Tag'], ['$ref' => '#/components/schemas/Note']], 'discriminator' => ['propertyName' => 'kind']],
                'App\Dto\Tag|App\Dto\Note',
            ],
            'allOf of constraints and then a reference' => [['allOf' => [['maxLength' => 5], ['$ref' => '#/components/schemas/Email']]], 'string'],
            'string anyOf with a typed and a constraining member' => [['type' => 'string', 'anyOf' => [['pattern' => 'a'], ['type' => 'integer']]], 'string|int'],
            'string constrained by anyOf' => [['type' => 'string', 'anyOf' => [['pattern' => 'a'], ['pattern' => 'b']]], 'string'],
            'oneOf with null first' => [['oneOf' => [['type' => 'null'], ['type' => 'string'], ['type' => 'integer']]], 'string|int|null'],
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
            'undeclared inline enum' => [['type' => 'string', 'enum' => ['a']], 'string', ["warning {$at}: This inline enum is not generated (only properties of generated classes get one), so the property keeps its plain type."]],
            'allOf of two typed schemas' => [['allOf' => [['type' => 'string'], ['type' => 'integer']]], 'mixed', ["error {$at}: \"allOf\" combines several typed schemas that are not objects, which no PHP type expresses; keep one of them."]],
            'allOf with oneOf' => [['allOf' => [['$ref' => '#/components/schemas/Tag']], 'oneOf' => [['type' => 'string']]], 'mixed', ["error {$at}: \"allOf\" together with \"oneOf\" or \"anyOf\" is not supported."]],
            'undeclared allOf object' => [['allOf' => [['$ref' => '#/components/schemas/Tag'], ['properties' => ['x' => []]]]], 'mixed', ["error {$at}: This inline object is not generated (only properties of generated classes get one); move it to components/schemas and use \$ref."]],
            'oneOf and anyOf together' => [['oneOf' => [['type' => 'string']], 'anyOf' => [['type' => 'boolean']]], 'string|bool', ["warning {$at}: \"oneOf\" and \"anyOf\" together become one union, which admits more than the schema does."]],
            'properties beside oneOf' => [['properties' => ['a' => []], 'oneOf' => [['type' => 'string']]], 'mixed', ["error {$at}: This inline object is not generated (only properties of generated classes get one); move it to components/schemas and use \$ref."]],
            'oneOf of untyped inline objects' => [
                ['oneOf' => [['properties' => ['a' => []]], ['type' => 'null'], ['properties' => ['b' => []]]]],
                'mixed',
                [
                    "error {$at}/oneOf/0: This inline object is not generated (only properties of generated classes get one); move it to components/schemas and use \$ref.",
                    "error {$at}/oneOf/2: This inline object is not generated (only properties of generated classes get one); move it to components/schemas and use \$ref.",
                ],
            ],
            'string with allOf and a typed oneOf' => [
                ['type' => 'string', 'allOf' => [['minLength' => 1]], 'oneOf' => [['type' => 'string'], ['type' => 'integer']]],
                'string|int',
                ["warning {$at}: \"allOf\" beside a typed \"oneOf\" or \"anyOf\" is not represented; the union alone gives the type."],
            ],
            'oneOf with an inline object' => [['oneOf' => [['type' => 'object', 'properties' => ['a' => []]], ['type' => 'string']]], 'mixed', ["error {$at}/oneOf/0: This inline object is not generated (only properties of generated classes get one); move it to components/schemas and use \$ref."]],
            'boolean enum' => [['type' => 'boolean', 'enum' => [true]], 'bool', ["warning {$at}: This enum has no string or integer value, so it is not generated and the property keeps its plain type."]],
            'nullable class by reference' => [['$ref' => '#/components/schemas/MaybeTag'], 'App\Dto\Tag|null', []],
            'map schema' => [['type' => 'object', 'additionalProperties' => ['type' => 'string']], 'array<array-key, string>', []],
            'nullable map schema' => [['type' => ['object', 'null'], 'additionalProperties' => ['type' => 'integer']], 'array<array-key, int>|null', []],
            'undeclared inline object' => [['type' => 'object', 'properties' => ['a' => []]], 'mixed', ["error {$at}: This inline object is not generated (only properties of generated classes get one); move it to components/schemas and use \$ref."]],
            'enum by reference' => [['$ref' => '#/components/schemas/Currency'], 'App\Dto\Currency', []],
            'nullable enum by reference' => [['$ref' => '#/components/schemas/MaybeCurrency'], 'App\Dto\MaybeCurrency|null', []],
            'ungenerated enum by reference' => [['$ref' => '#/components/schemas/Failed'], 'mixed', []],
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

    public function testRecognisesEnumSchemas(): void
    {
        $graph = $this->graph(['value' => []]);
        $shapes = [];
        foreach ($graph->all() as $resolved) {
            $shapes[$resolved->name()] = SchemaShape::isEnum($resolved->schema());
        }

        self::assertTrue($shapes['Currency']);
        self::assertFalse($shapes['Holder']);
        self::assertFalse($shapes['Email']);
        self::assertFalse(SchemaShape::isEnum(GraphFixture::load(['E' => ['enum' => ['a'], 'x-php-type' => 'App\\Money']])->all()[0]->schema()));
        self::assertFalse(SchemaShape::isEnum(GraphFixture::load(['E' => ['enum' => [true, null]]])->all()[0]->schema()));
        self::assertFalse(SchemaShape::isEnum(GraphFixture::load(['E' => ['type' => 'object', 'enum' => [['a' => 1]], 'properties' => ['a' => []]]])->all()[0]->schema()));
        self::assertTrue(SchemaShape::isEnum(GraphFixture::load(['E' => ['enum' => [1.5, 'a']]])->all()[0]->schema()));
        self::assertFalse(SchemaShape::isEnum(GraphFixture::load(['E' => ['enum' => ['a'], 'oneOf' => [['type' => 'string']]]])->all()[0]->schema()));
        self::assertFalse(SchemaShape::isEnum(GraphFixture::load(['E' => ['enum' => ['a'], '$ref' => '#/components/schemas/F'], 'F' => ['type' => 'string']])->all()[0]->schema()));
    }

    public function testRecognisesClassShapedSchemas(): void
    {
        $graph = $this->graph(['value' => []]);
        $shapes = [];
        foreach ($graph->all() as $resolved) {
            $shapes[$resolved->name()] = SchemaShape::isClass($resolved->schema());
        }

        self::assertSame(
            ['Holder' => true, 'Tag' => true, 'Note' => true, 'Email' => false, 'MaybeCount' => false, 'Currency' => false, 'MaybeTag' => true, 'MaybeCurrency' => false, 'Failed' => false, 'LoopA' => false, 'LoopB' => false, 'Hidden' => true, 'Free' => false, 'RefWithProperties' => false, 'StringWithProperties' => false],
            $shapes,
        );
    }

    public function testUsesTheDeclarationOfAnInlineSchema(): void
    {
        $graph = GraphFixture::load(['Holder' => ['type' => 'object', 'properties' => [
            'address' => ['type' => 'object', 'properties' => ['city' => []]],
            'status' => ['enum' => ['on', null]],
        ]]]);
        $at = '/project/api/openapi.yaml#/components/schemas/Holder/properties/';
        $mapper = new TypeMapper(
            $graph,
            new Declarations(
                [$at . 'address' => ClassName::fromFqcn('App\Dto\HolderAddress')],
                [$at . 'status' => new EnumType(ClassName::fromFqcn('App\Dto\HolderStatus'), EnumBacking::from(EnumBacking::STRING), ['on' => 'ON'])],
            ),
            EmitterFixture::target('8.2', Mutability::IMMUTABLE),
            [],
        );
        $holder = $graph->all()[0]->schema();
        $diagnostics = new Diagnostics();

        self::assertSame('App\Dto\HolderAddress', $mapper->map($holder->requireProperty('address'), $diagnostics)->describe());
        self::assertSame('App\Dto\HolderStatus|null', $mapper->map($holder->requireProperty('status'), $diagnostics)->describe());
        self::assertSame([], $diagnostics->all());
    }

    /**
     * @return Declarations the classes, enums and skipped schemas of the shared graph
     */
    private function declarations(): Declarations
    {
        $at = '/project/api/openapi.yaml#/components/schemas/';

        return new Declarations(
            [self::TAG => ClassName::fromFqcn('App\Dto\Tag'), $at . 'MaybeTag' => ClassName::fromFqcn('App\Dto\Tag'), $at . 'Note' => ClassName::fromFqcn('App\Dto\Note')],
            [
                $at . 'Currency' => new EnumType(ClassName::fromFqcn('App\Dto\Currency'), EnumBacking::from(EnumBacking::STRING), ['EUR' => 'EUR']),
                $at . 'MaybeCurrency' => new EnumType(ClassName::fromFqcn('App\Dto\MaybeCurrency'), EnumBacking::from(EnumBacking::STRING), ['EUR' => 'EUR']),
            ],
            [$at . 'Hidden' => true],
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
            $this->declarations(),
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
            'Note' => ['type' => 'object', 'properties' => ['text' => ['type' => 'string']]],
            'Email' => ['type' => 'string', 'format' => 'email'],
            'MaybeCount' => ['type' => ['integer', 'null']],
            'Currency' => ['type' => 'string', 'enum' => ['EUR']],
            'MaybeTag' => ['type' => ['object', 'null'], 'properties' => ['label' => ['type' => 'string']]],
            'MaybeCurrency' => ['enum' => ['EUR', null]],
            'Failed' => ['enum' => ['a', 1]],
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
            new Declarations(),
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

    /**
     * @dataProvider compositions
     *
     * @param array<array-key, mixed> $schema
     */
    public function testRecognisesComposedClasses(array $schema, bool $class, bool $discriminated): void
    {
        $graph = GraphFixture::load([
            'S' => $schema,
            'Tag' => ['type' => 'object', 'properties' => ['label' => []]],
            'Note' => ['type' => 'object', 'properties' => ['text' => []]],
            'Email' => ['type' => 'string'],
        ]);
        $resolved = $graph->all()[0]->schema();

        self::assertSame([$class, $discriminated], [SchemaShape::isClass($resolved), SchemaShape::isDiscriminated($resolved)]);
    }

    /**
     * @return array<string, array{array<array-key, mixed>, bool, bool}>
     */
    public static function compositions(): array
    {
        $tag = ['$ref' => '#/components/schemas/Tag'];
        $note = ['$ref' => '#/components/schemas/Note'];
        $discriminator = ['propertyName' => 'kind'];

        return [
            'allOf wrapper' => [['allOf' => [$tag], 'description' => 'x'], false, false],
            'allOf wrapper with constraints' => [['allOf' => [['$ref' => '#/components/schemas/Email'], ['maxLength' => 5]]], false, false],
            'allOf reference and inline object' => [['allOf' => [$tag, ['properties' => ['a' => []]]]], true, false],
            'allOf of two references' => [['allOf' => [$tag, $note]], true, false],
            'inline object after a constraint' => [['allOf' => [['description' => 'x'], ['properties' => ['a' => []]]]], true, false],
            'allOf of one inline object' => [['allOf' => [['type' => 'object', 'properties' => ['a' => []]]]], true, false],
            'allOf of a nested composition' => [['allOf' => [['allOf' => [$tag, $note]]]], true, false],
            'allOf reference and own properties' => [['allOf' => [$tag], 'properties' => ['a' => []]], true, false],
            'allOf on a string' => [['type' => 'string', 'allOf' => [$tag, $note]], false, false],
            'allOf with oneOf' => [['allOf' => [$tag, $note], 'oneOf' => [$tag]], false, false],
            'oneOf with discriminator' => [['oneOf' => [$tag, $note], 'discriminator' => $discriminator], true, true],
            'anyOf with discriminator' => [['anyOf' => [$tag, $note], 'discriminator' => $discriminator], true, true],
            'oneOf without discriminator' => [['oneOf' => [$tag, $note]], false, false],
            'properties beside oneOf' => [['properties' => ['a' => []], 'oneOf' => [['required' => ['a']]]], true, false],
            'discriminator alone' => [['properties' => ['a' => []], 'discriminator' => $discriminator], true, false],
            'discriminator on a reference' => [['$ref' => '#/components/schemas/Tag', 'oneOf' => [$tag], 'discriminator' => $discriminator], false, false],
            'discriminator with x-php-type' => [['oneOf' => [$tag], 'discriminator' => $discriminator, 'x-php-type' => 'App\\Pet'], false, false],
        ];
    }
}
