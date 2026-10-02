<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Contract;

use MSSTC4PHP\DtoGenerator\Contract\SchemaReferences;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaGraph;
use MSSTC4PHP\DtoGenerator\Tests\Support\GraphFixture;
use PHPUnit\Framework\TestCase;

final class SchemaReferencesTest extends TestCase
{
    public function testFollowsAReferenceAndItsChainToTheSchemaThatDescribesTheValue(): void
    {
        $graph = GraphFixture::load([
            'Pet' => ['type' => 'object', 'properties' => [
                'email' => ['$ref' => '#/components/schemas/Email'],
                'contact' => ['$ref' => '#/components/schemas/Contact'],
                'name' => ['type' => 'string', 'maxLength' => 10],
            ]],
            'Email' => ['type' => 'string', 'format' => 'email'],
            'Contact' => ['$ref' => '#/components/schemas/Email'],
        ]);
        $references = new SchemaReferences($graph);
        $pet = $this->schema($graph, 'Pet');

        self::assertSame('email', $references->resolve($pet->requireProperty('email'))->format());
        self::assertSame('email', $references->resolve($pet->requireProperty('contact'))->format());
        self::assertSame($pet->requireProperty('name'), $references->resolve($pet->requireProperty('name')));
    }

    public function testLeavesTheKeywordsBesideAReferenceOnItsOwnSchema(): void
    {
        $graph = GraphFixture::load([
            'Pet' => ['type' => 'object', 'properties' => ['email' => ['$ref' => '#/components/schemas/Email', 'maxLength' => 64]]],
            'Email' => ['type' => 'string', 'format' => 'email'],
        ]);
        $email = $this->schema($graph, 'Pet')->requireProperty('email');
        $resolved = (new SchemaReferences($graph))->resolve($email);

        self::assertSame(64, $email->keyword('maxLength'));
        self::assertFalse($resolved->hasKeyword('maxLength'));
        self::assertSame('email', $resolved->format());
    }

    public function testListsEverySchemaOnTheWayWithItsOwnKeywords(): void
    {
        $graph = GraphFixture::load([
            'Pet' => ['type' => 'object', 'properties' => ['code' => ['$ref' => '#/components/schemas/Short', 'maxLength' => 5]]],
            'Short' => ['$ref' => '#/components/schemas/Code', 'minLength' => 2],
            'Code' => ['type' => 'string', 'maxLength' => 8],
            'Loop' => ['$ref' => '#/components/schemas/Back'],
            'Back' => ['$ref' => '#/components/schemas/Loop'],
        ], [], null, false);
        $references = new SchemaReferences($graph);
        $code = $this->schema($graph, 'Pet')->requireProperty('code');

        self::assertSame([$code, $this->schema($graph, 'Short'), $this->schema($graph, 'Code')], $references->chain($code));
        self::assertSame([$this->schema($graph, 'Loop'), $this->schema($graph, 'Back')], $references->chain($this->schema($graph, 'Loop')));
        self::assertSame([$code], SchemaReferences::none()->chain($code));
    }

    public function testKeepsAReferenceItCannotFollow(): void
    {
        $graph = GraphFixture::load([
            'Pet' => ['type' => 'object', 'properties' => ['owner' => ['$ref' => '#/components/schemas/Gone']]],
            'Loop' => ['$ref' => '#/components/schemas/Back'],
            'Back' => ['$ref' => '#/components/schemas/Loop'],
        ], [], null, false);
        $references = new SchemaReferences($graph);
        $owner = $this->schema($graph, 'Pet')->requireProperty('owner');

        self::assertSame($owner, $references->resolve($owner));
        $loop = $this->schema($graph, 'Loop');
        self::assertSame($loop, $references->resolve($loop));
    }

    public function testResolvesNothingWithoutAGraph(): void
    {
        $graph = GraphFixture::load([
            'Pet' => ['type' => 'object', 'properties' => ['email' => ['$ref' => '#/components/schemas/Email']]],
            'Email' => ['type' => 'string'],
        ]);
        $email = $this->schema($graph, 'Pet')->requireProperty('email');

        self::assertSame($email, SchemaReferences::none()->resolve($email));
    }

    private function schema(SchemaGraph $graph, string $name): Schema
    {
        foreach ($graph->all() as $resolved) {
            if ($resolved->name() === $name) {
                return $resolved->schema();
            }
        }

        self::fail('No schema ' . $name);
    }
}
