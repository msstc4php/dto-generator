<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Builder\SchemaFingerprint;
use MSSTC4PHP\DtoGenerator\Domain\Builder\SchemaParser;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use PHPUnit\Framework\TestCase;

final class SchemaFingerprintTest extends TestCase
{
    private const SCHEMA = [
        'type' => 'object',
        'description' => 'A point.',
        'properties' => ['x' => ['type' => 'integer'], 'tags' => ['type' => 'array', 'items' => ['type' => 'string']]],
        'required' => ['x'],
        'additionalProperties' => ['type' => 'string'],
        'minProperties' => 1,
        'maxProperties' => 5,
        'x-php-final' => true,
        'x-php-name' => 'point',
        'allOf' => [['minProperties' => 1]],
        'oneOf' => [['required' => ['x']]],
        'anyOf' => [['required' => ['x']]],
        'discriminator' => ['propertyName' => 'x', 'mapping' => ['1' => '#/components/schemas/P']],
    ];

    public function testTellsTwoSpellingsOfAFormatApart(): void
    {
        // The config may map one spelling and not the other.
        self::assertNotSame(SchemaFingerprint::of($this->schema(['type' => 'string', 'format' => 'url'])), SchemaFingerprint::of($this->schema(['type' => 'string', 'format' => 'uri-reference'])));
    }

    public function testLeavesOutWhereTheSchemaIs(): void
    {
        self::assertSame(
            SchemaFingerprint::of($this->schema(self::SCHEMA, 'a.yaml', '/A')),
            SchemaFingerprint::of($this->schema(self::SCHEMA, 'b.yaml', '/B/properties/c')),
        );
    }

    public function testLeavesOutTheOrderOfKeywordsAndRequiredButNotOfProperties(): void
    {
        $node = ['minProperties' => 1, 'maxProperties' => 2, 'x-a' => 1, 'x-b' => 2, 'properties' => ['a' => [], 'b' => []], 'required' => ['a', 'b']];
        $fingerprint = SchemaFingerprint::of($this->schema($node));

        self::assertSame($fingerprint, SchemaFingerprint::of($this->schema(['maxProperties' => 2, 'x-b' => 2, 'x-a' => 1, 'minProperties' => 1, 'required' => ['b', 'a']] + $node)));
        self::assertNotSame($fingerprint, SchemaFingerprint::of($this->schema(['properties' => ['b' => [], 'a' => []]] + $node)));
    }

    /**
     * @param array<array-key, mixed> $changes a null leaves the keyword out
     *
     * @dataProvider changes
     */
    public function testTellsSchemasThatSayAnythingElseApart(array $changes): void
    {
        self::assertNotSame(SchemaFingerprint::of($this->schema(self::SCHEMA)), SchemaFingerprint::of($this->schema(array_filter(array_replace(self::SCHEMA, $changes), static fn ($value): bool => $value !== null))));
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>}>
     */
    public static function changes(): iterable
    {
        yield 'type' => [['type' => ['object', 'null']]];
        yield 'ref' => [['$ref' => '#/components/schemas/P']];
        yield 'format' => [['format' => 'point']];
        yield 'description' => [['description' => 'Another point.']];
        yield 'deprecated' => [['deprecated' => true]];
        yield 'default' => [['default' => ['x' => 1]]];
        yield 'enum' => [['enum' => [['x' => 1]]]];
        yield 'a property' => [['properties' => ['x' => ['type' => 'number'], 'tags' => ['type' => 'array', 'items' => ['type' => 'string']]]]];
        yield 'the name of a property' => [['properties' => ['y' => ['type' => 'integer'], 'tags' => ['type' => 'array', 'items' => ['type' => 'string']]]]];
        yield 'the items of a property' => [['properties' => ['x' => ['type' => 'integer'], 'tags' => ['type' => 'array', 'items' => ['type' => 'integer']]]]];
        yield 'required' => [['required' => ['x', 'z']]];
        yield 'additional properties' => [['additionalProperties' => ['type' => 'integer']]];
        yield 'additional properties forbidden' => [['additionalProperties' => false]];
        yield 'allOf' => [['allOf' => [['minProperties' => 2]]]];
        yield 'oneOf' => [['oneOf' => [['required' => ['tags']]]]];
        yield 'anyOf' => [['anyOf' => [['required' => ['tags']]]]];
        yield 'no discriminator' => [['discriminator' => null]];
        yield 'discriminator property' => [['discriminator' => ['propertyName' => 'tags', 'mapping' => ['1' => '#/components/schemas/P']]]];
        yield 'discriminator value' => [['discriminator' => ['propertyName' => 'x', 'mapping' => ['2' => '#/components/schemas/P']]]];
        yield 'discriminator default' => [['discriminator' => ['propertyName' => 'x', 'mapping' => ['1' => '#/components/schemas/P'], 'defaultMapping' => '#/components/schemas/P']]];
        yield 'discriminator target' => [['discriminator' => ['propertyName' => 'x', 'mapping' => ['1' => '#/components/schemas/Q']]]];
        yield 'keyword' => [['minProperties' => 2]];
        yield 'another keyword' => [['maxProperties' => 6]];
        yield 'extension' => [['x-php-final' => false]];
        yield 'another extension' => [['x-php-name' => 'spot']];
    }

    public function testLeavesOutOnlyTheTitleOfTheSchemaItself(): void
    {
        $fingerprint = SchemaFingerprint::of($this->schema(self::SCHEMA));
        self::assertSame($fingerprint, SchemaFingerprint::of($this->schema(['title' => 'Point'] + self::SCHEMA)));

        $titled = ['type' => 'string', 'title' => 'Tag'];
        foreach ([
            ['properties' => ['x' => ['type' => 'integer', 'title' => 'X'], 'tags' => ['type' => 'array', 'items' => ['type' => 'string']]]],
            ['properties' => ['x' => ['type' => 'integer'], 'tags' => ['type' => 'array', 'items' => $titled]]],
            ['additionalProperties' => $titled],
            ['allOf' => [['minProperties' => 1, 'title' => 'Part']]],
            ['oneOf' => [['required' => ['x'], 'title' => 'One']]],
            ['anyOf' => [['required' => ['x'], 'title' => 'Any']]],
        ] as $changes) {
            self::assertNotSame($fingerprint, SchemaFingerprint::of($this->schema(array_replace(self::SCHEMA, $changes))));
        }
    }

    public function testTellsTheMembersOfCompositionsApart(): void
    {
        $fingerprints = [];
        foreach (['allOf', 'oneOf', 'anyOf'] as $keyword) {
            foreach ([['required' => ['x']], ['required' => ['tags']]] as $member) {
                $fingerprints[] = SchemaFingerprint::of($this->schema([$keyword => [$member]]));
            }
        }

        $mapped = static fn (string $ref): string => SchemaFingerprint::of(
            (new SchemaParser())->parse(['discriminator' => ['propertyName' => 'k', 'mapping' => ['a' => $ref]]], new SchemaLocation('a.yaml', '/S'), new Diagnostics()),
        );
        $fingerprints[] = $mapped('#/components/schemas/P');
        $fingerprints[] = $mapped('#/components/schemas/Q');

        self::assertSame($fingerprints, array_values(array_unique($fingerprints)));
    }

    /**
     * @param array<array-key, mixed> $node
     */
    private function schema(array $node, string $file = 'a.yaml', string $pointer = '/S'): Schema
    {
        return (new SchemaParser())->parse($node, new SchemaLocation($file, $pointer), new Diagnostics());
    }
}
