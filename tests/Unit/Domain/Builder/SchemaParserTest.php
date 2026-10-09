<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Builder\SchemaParser;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostic;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaType;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;
use PHPUnit\Framework\TestCase;

/**
 * @phpstan-import-type JsonValue from Json
 */
final class SchemaParserTest extends TestCase
{
    public function testParsesAFullSchema(): void
    {
        $diagnostics = new Diagnostics();
        $schema = (new SchemaParser())->parse([
            'type' => ['object', 'null'],
            'description' => 'A user',
            'deprecated' => true,
            'required' => ['id'],
            'properties' => [
                'id' => ['type' => 'string', 'format' => 'uuid', 'minLength' => 1],
                'tags' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Tag']],
                'meta' => ['additionalProperties' => ['type' => 'integer']],
                '200' => ['type' => 'boolean'],
            ],
            'default' => null,
            'x-php-class-name' => 'Account',
        ], $this->root(), $diagnostics);

        self::assertSame([], $diagnostics->all());
        self::assertTrue($schema->isNullable());
        self::assertSame('A user', $schema->description());
        self::assertTrue($schema->isDeprecated());
        self::assertSame(['id', 'tags', 'meta', '200'], $schema->propertyNames());
        self::assertTrue($schema->isRequired('id'));
        self::assertNotNull($schema->default());
        self::assertSame('Account', $schema->extensions()->get('x-php-class-name'));

        $id = $schema->property('id');
        self::assertNotNull($id);
        self::assertSame('uuid', $id->format());
        self::assertSame(1, $id->keyword('minLength'));
        self::assertSame('a.yaml#/components/schemas/User/properties/id', $id->location()->toString());

        $tags = $schema->property('tags');
        self::assertNotNull($tags);
        $items = $tags->items();
        self::assertNotNull($items);
        self::assertSame('#/components/schemas/Tag', $items->ref());
        self::assertSame('/components/schemas/User/properties/tags/items', $items->location()->pointer());

        $meta = $schema->property('meta');
        self::assertNotNull($meta);
        self::assertInstanceOf(Schema::class, $meta->additionalProperties());
    }

    public function testParsesCompositionAndDiscriminator(): void
    {
        $diagnostics = new Diagnostics();
        $schema = (new SchemaParser())->parse([
            'allOf' => [['$ref' => '#/components/schemas/Base']],
            'oneOf' => [['$ref' => '#/components/schemas/Cat'], ['$ref' => '#/components/schemas/Dog']],
            'anyOf' => [['type' => 'string']],
            'discriminator' => ['propertyName' => 'kind', 'mapping' => ['cat' => 'Cat']],
            'enum' => ['a', 'b'],
            'additionalProperties' => false,
        ], $this->root(), $diagnostics);

        self::assertSame([], $diagnostics->all());
        self::assertCount(1, $schema->allOf());
        self::assertCount(2, $schema->oneOf());
        self::assertSame('/components/schemas/User/oneOf/1', $schema->oneOf()[1]->location()->pointer());
        self::assertCount(1, $schema->anyOf());
        self::assertNotNull($schema->discriminator());
        self::assertSame('#/components/schemas/Cat', $schema->discriminator()->refFor('cat'));
        self::assertSame(['a', 'b'], $schema->enum());
        self::assertFalse($schema->additionalProperties());
    }

    public function testTrueIsTheEmptySchema(): void
    {
        $diagnostics = new Diagnostics();
        $schema = (new SchemaParser())->parse(true, $this->root(), $diagnostics);

        self::assertSame([], $diagnostics->all());
        self::assertSame([], $schema->types());
    }

    public function testAnEmptyObjectIsTheEmptySchema(): void
    {
        $diagnostics = new Diagnostics();
        (new SchemaParser())->parse([], $this->root(), $diagnostics);

        self::assertSame([], $diagnostics->all());
    }

    public function testAcceptsNumericRequiredNamesFromUnquotedYaml(): void
    {
        $schema = (new SchemaParser())->parse(['required' => [200]], $this->root(), new Diagnostics());

        self::assertSame(['200'], $schema->required());
    }

    public function testWarnsAboutRepeatsAndKeepsOneCopy(): void
    {
        $diagnostics = new Diagnostics();
        $schema = (new SchemaParser())->parse(['type' => ['string', 'string'], 'required' => ['a', 'a']], $this->root(), $diagnostics);

        self::assertFalse($diagnostics->hasErrors());
        self::assertSame(
            ['Type "string" is listed twice.', '"a" is listed twice.'],
            array_map(static fn (Diagnostic $diagnostic): string => $diagnostic->message(), $diagnostics->all()),
        );
        self::assertSame([SchemaType::from(SchemaType::STRING)], $schema->types());
        self::assertSame(['a'], $schema->required());
    }

    public function testReportsEveryProblemInOnePass(): void
    {
        $diagnostics = new Diagnostics();
        (new SchemaParser())->parse(['type' => 'text', 'format' => 1, 'enum' => []], $this->root(), $diagnostics);

        self::assertCount(3, $diagnostics->errors());
    }

    /**
     * @dataProvider invalidNodes
     *
     * @param JsonValue $node
     */
    public function testReportsInvalidKeywordsWithTheirLocation($node, string $message, string $pointer): void
    {
        $diagnostics = new Diagnostics();
        (new SchemaParser())->parse($node, $this->root(), $diagnostics);

        self::assertCount(1, $diagnostics->errors());
        $error = $diagnostics->errors()[0];
        self::assertStringContainsString($message, $error->message());
        self::assertSame('/components/schemas/User' . $pointer, $error->location()->pointer());
    }

    /**
     * @return array<string, array{JsonValue, string, string}>
     */
    public static function invalidNodes(): array
    {
        return [
            'list instead of object' => [[1, 2], 'A schema must be an object', ''],
            'scalar' => ['string', 'A schema must be an object', ''],
            'false schema' => [false, 'The "false" schema is not supported', ''],
            'unknown type' => [['type' => 'text'], 'Unknown type "text"', '/type'],
            'unknown type in list' => [['type' => ['string', 'text']], 'Unknown type "text"', '/type/1'],
            'non-string type in list' => [['type' => ['string', 1]], 'Unknown type integer', '/type/1'],
            'empty type list' => [['type' => []], 'non-empty list of type names', '/type'],
            'empty ref' => [['$ref' => ''], '"$ref" must be a non-empty string', '/$ref'],
            'numeric format' => [['format' => 5], '"format" must be a non-empty string', '/format'],
            'description object' => [['description' => ['a' => 1]], '"description" must be a string', '/description'],
            'deprecated string' => [['deprecated' => 'yes'], '"deprecated" must be a boolean', '/deprecated'],
            'empty enum' => [['enum' => []], '"enum" must be a non-empty list', '/enum'],
            'enum map' => [['enum' => ['a' => 1]], '"enum" must be a non-empty list', '/enum'],
            'properties list' => [['properties' => [['type' => 'string']]], '"properties" must be an object', '/properties'],
            'required map' => [['required' => ['a' => 'id']], '"required" must be a list', '/required'],
            'required non-string' => [['required' => [true]], 'A required property name must be a string', '/required/0'],
            'tuple items' => [['items' => [['type' => 'string']]], 'tuple arrays', '/items'],
            'additionalProperties string' => [['additionalProperties' => 'yes'], 'must be a boolean or a schema', '/additionalProperties'],
            'additionalProperties list' => [['additionalProperties' => [1]], 'must be a boolean or a schema', '/additionalProperties'],
            'empty allOf' => [['allOf' => []], '"allOf" must be a non-empty list of schemas', '/allOf'],
            'oneOf map' => [['oneOf' => ['a' => []]], '"oneOf" must be a non-empty list of schemas', '/oneOf'],
            'anyOf scalar' => [['anyOf' => 'x'], '"anyOf" must be a non-empty list of schemas', '/anyOf'],
            'discriminator without property' => [['discriminator' => ['mapping' => []]], 'needs a non-empty "propertyName"', '/discriminator'],
            'discriminator mapping scalar' => [['discriminator' => ['propertyName' => 'kind', 'mapping' => 'x']], '"mapping" must be an object', '/discriminator/mapping'],
            'discriminator bad target' => [['discriminator' => ['propertyName' => 'kind', 'mapping' => ['cat' => 1]]], 'A mapping target must be a non-empty string', '/discriminator/mapping/cat'],
            'nested error keeps its location' => [['properties' => ['id' => ['type' => 'uuid']]], 'Unknown type "uuid"', '/properties/id/type'],
            'scalar items' => [['items' => 'string'], 'A schema must be an object', '/items'],
            'unquoted null type' => [['type' => ['string', null]], 'Unknown type null; quote it as "null"', '/type/1'],
            'mapping as list' => [['discriminator' => ['propertyName' => 'kind', 'mapping' => ['Dog', 'Cat']]], '"mapping" must be an object', '/discriminator/mapping'],
            'numeric type' => [['type' => 5], 'non-empty list of type names', '/type'],
            'numeric mapping key' => [['discriminator' => ['propertyName' => 'kind', 'mapping' => ['1' => 5]]], 'A mapping target must be a non-empty string', '/discriminator/mapping/1'],
        ];
    }

    /**
     * @dataProvider malformedMembers
     *
     * @param array<string, JsonValue> $node
     */
    public function testKeepsNoMembersFromAMalformedKeyword(array $node, string $message): void
    {
        $diagnostics = new Diagnostics();
        $schema = (new SchemaParser())->parse($node, $this->root(), $diagnostics);

        self::assertSame([$message], array_map(static fn (Diagnostic $d): string => $d->toString(), $diagnostics->all()));
        self::assertSame([], $schema->propertyNames());
        self::assertSame([], $schema->required());
    }

    /**
     * @return array<string, array{array<string, JsonValue>, string}>
     */
    public static function malformedMembers(): array
    {
        $at = 'error a.yaml#/components/schemas/User';

        return [
            'properties string' => [['properties' => 'id'], "{$at}/properties: \"properties\" must be an object."],
            'properties list' => [['properties' => [['type' => 'string']]], "{$at}/properties: \"properties\" must be an object."],
            'required string' => [['required' => 'id'], "{$at}/required: \"required\" must be a list of property names."],
            'required map' => [['required' => ['a' => 'id']], "{$at}/required: \"required\" must be a list of property names."],
        ];
    }

    public function testWarnsAboutKeywordsWithNoEffectOnTheType(): void
    {
        $diagnostics = new Diagnostics();
        (new SchemaParser())->parse(
            ['type' => 'object', 'patternProperties' => ['^x' => ['type' => 'string']], 'not' => ['type' => 'null'], 'pattern' => '^a', 'readOnly' => true],
            $this->root(),
            $diagnostics,
        );

        self::assertSame(
            [
                'warning a.yaml#/components/schemas/User/patternProperties: "patternProperties" is not supported and has no effect on the generated type.',
                'warning a.yaml#/components/schemas/User/not: "not" is not supported and has no effect on the generated type.',
            ],
            array_map(static fn (Diagnostic $d): string => $d->toString(), $diagnostics->all()),
        );
    }

    public function testStaysQuietWhereTheTypeIgnoresTheSchema(): void
    {
        $diagnostics = new Diagnostics();
        (new SchemaParser())->parse(['type' => 'string', 'nullable' => false, 'properties' => ['a' => ['x-php-type' => 'App\\A', 'not' => []]], 'additionalProperties' => ['x-php-skip' => true, 'contains' => []]], $this->root(), $diagnostics);

        self::assertSame([], $diagnostics->all());
    }

    public function testStaysQuietBelowASchemaTheTypeIgnores(): void
    {
        $diagnostics = new Diagnostics();
        (new SchemaParser())->parse(
            ['x-php-type' => 'App\\M', 'properties' => ['a' => ['not' => [], 'nullable' => true]], 'items' => ['contains' => []]],
            $this->root(),
            $diagnostics,
        );
        (new SchemaParser())->parse(['type' => 'object', 'properties' => ['a' => ['x-php-skip' => true, 'properties' => ['b' => ['not' => []]]]]], $this->root(), $diagnostics);
        $parser = new SchemaParser();
        $parser->parse(['x-php-skip' => true, 'not' => []], $this->root(), $diagnostics);
        // An x-php-type that is no class name replaces nothing.
        $parser->parse(['x-php-type' => 123, 'properties' => ['a' => ['not' => []]]], $this->root(), $diagnostics);
        // The parser is reused: quiet below one schema, it warns again for the next.
        $parser->parse(['not' => []], $this->root(), $diagnostics);

        self::assertSame(
            [
                'warning a.yaml#/components/schemas/User/properties/a/not: "not" is not supported and has no effect on the generated type.',
                'warning a.yaml#/components/schemas/User/not: "not" is not supported and has no effect on the generated type.',
            ],
            array_map(static fn (Diagnostic $d): string => $d->toString(), $diagnostics->all()),
        );
    }

    public function testPointsOpenApi30NullableAtTheTypeList(): void
    {
        $diagnostics = new Diagnostics();
        (new SchemaParser())->parse(['type' => 'string', 'nullable' => true], $this->root(), $diagnostics);

        self::assertSame(
            ['warning a.yaml#/components/schemas/User/nullable: "nullable" is OpenAPI 3.0 and has no effect in 3.1; write type: [T, \'null\'].'],
            array_map(static fn (Diagnostic $d): string => $d->toString(), $diagnostics->all()),
        );
    }

    private function root(): SchemaLocation
    {
        return new SchemaLocation('a.yaml', '/components/schemas/User');
    }

    public function testKeepsWhatFollowsASkippedEntry(): void
    {
        $diagnostics = new Diagnostics();
        $schema = (new SchemaParser())->parse([
            'x-first' => 1,
            200 => 'numeric keyword',
            'type' => ['text', 'string', 'string', 'integer'],
            'required' => [true, 'a', 'a', 'b'],
        ], $this->root(), $diagnostics);

        self::assertSame('numeric keyword', $schema->keyword('200'));
        self::assertSame([SchemaType::from(SchemaType::STRING), SchemaType::from(SchemaType::INTEGER)], $schema->types());
        self::assertSame(['a', 'b'], $schema->required());
        self::assertCount(2, $diagnostics->errors());
    }
}
