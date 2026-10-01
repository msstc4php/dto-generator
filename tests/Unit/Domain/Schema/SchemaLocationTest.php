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

    public function testRejectsARelativePointer(): void
    {
        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage('must be empty or start with "/"');

        new SchemaLocation('a.json', 'components');
    }
}
