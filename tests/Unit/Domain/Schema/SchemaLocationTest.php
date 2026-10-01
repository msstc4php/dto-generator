<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Schema;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use PHPUnit\Framework\TestCase;

final class SchemaLocationTest extends TestCase
{
    public function testRendersFileAndPointer(): void
    {
        $location = (new SchemaLocation('public.yaml'))->child('components', 'schemas', 'User');

        self::assertSame('public.yaml', $location->file());
        self::assertSame('/components/schemas/User', $location->pointer());
        self::assertSame('public.yaml#/components/schemas/User', $location->toString());
    }

    public function testTheDocumentRootHasAnEmptyPointer(): void
    {
        self::assertSame('public.yaml#', (new SchemaLocation('public.yaml'))->toString());
    }

    public function testEscapesSlashAndTildeInSegments(): void
    {
        $location = (new SchemaLocation('a.json'))->child('properties', 'a/b', 'x~y');

        self::assertSame('/properties/a~1b/x~0y', $location->pointer());
    }

    public function testComparesByValue(): void
    {
        self::assertTrue((new SchemaLocation('a.json', '/x'))->equals((new SchemaLocation('a.json'))->child('x')));
        self::assertFalse((new SchemaLocation('a.json', '/x'))->equals(new SchemaLocation('b.json', '/x')));
    }

    public function testRejectsAnEmptyFile(): void
    {
        $this->expectException(InvalidModel::class);

        new SchemaLocation('');
    }

    /**
     * @dataProvider invalidEscapes
     */
    public function testRejectsInvalidEscapes(string $pointer): void
    {
        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage('invalid "~" escape');

        new SchemaLocation('a.json', $pointer);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidEscapes(): array
    {
        return [
            'unknown escape' => ['/a~2'],
            'trailing tilde' => ['/a~'],
        ];
    }

    public function testAcceptsValidEscapes(): void
    {
        self::assertSame('/a~0b~1', (new SchemaLocation('a.json', '/a~0b~1'))->pointer());
    }

    public function testRejectsARelativePointer(): void
    {
        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage('must be empty or start with "/"');

        new SchemaLocation('a.json', 'components');
    }
}
