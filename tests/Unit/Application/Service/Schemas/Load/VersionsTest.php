<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Application\Service\Schemas\Load;

use MSSTC4PHP\DtoGenerator\Application\Port\Document;
use MSSTC4PHP\DtoGenerator\Application\Service\Schemas\Load\Versions;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostic;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;
use PHPUnit\Framework\TestCase;

/**
 * @phpstan-import-type JsonValue from Json
 */
final class VersionsTest extends TestCase
{
    /**
     * @param JsonValue $version
     *
     * @dataProvider versions
     */
    public function testTellsOpenApi30FromItsVersion($version, ?string $normalized, bool $openApi30, bool $understood): void
    {
        self::assertSame($normalized, Versions::normalize($version));
        self::assertSame($understood, $normalized !== null && Versions::isUnderstood($normalized));
        self::assertSame($openApi30, (new Versions())->ofSource(new Document('/p/a.yaml', ['openapi' => $version])));
    }

    /**
     * @return iterable<string, array{JsonValue, ?string, bool, bool}>
     */
    public static function versions(): iterable
    {
        yield '3.0.3' => ['3.0.3', '3.0.3', true, true];
        yield '3.0' => ['3.0', '3.0', true, true];
        yield 'a float' => [3.0, '3.0', true, true];
        yield '3.1.0' => ['3.1.0', '3.1.0', false, true];
        yield '3.0.3 with a suffix' => ['3.0.3-rc', '3.0.3-rc', false, false];
        yield 'a prefix before 3.0' => ['v3.0', 'v3.0', false, false];
        yield '3.00' => ['3.00', '3.00', false, false];
        yield '3.1' => ['3.1', '3.1', false, true];
        yield '3.1.0 with a suffix' => ['3.1.0-rc', '3.1.0-rc', false, false];
        yield 'a prefix before 3.1' => ['v3.1', 'v3.1', false, false];
        yield '3.2.0' => ['3.2.0', '3.2.0', false, false];
        yield 'an integer' => [3, null, false, false];
        yield 'none' => [null, null, false, false];
    }

    public function testReadsADocumentWithoutAVersionInThatOfTheDocumentReferringToItFirst(): void
    {
        $versions = new Versions();
        $diagnostics = new Diagnostics();
        $old = new Document('/p/old.yaml', ['openapi' => '3.0.3']);
        $new = new Document('/p/new.yaml', ['openapi' => '3.1.0']);
        $shared = new Document('/p/shared.yaml', []);
        $deeper = new Document('/p/deeper.yaml', []);
        $stated = new Document('/p/stated.yaml', ['openapi' => '3.1.0']);

        self::assertTrue($versions->ofSource($old));
        self::assertFalse($versions->ofSource($new));
        self::assertTrue($versions->ofReferenced($shared, new SchemaLocation('/p/old.yaml', '/a'), $diagnostics));
        self::assertTrue($versions->ofReferenced($deeper, new SchemaLocation('/p/shared.yaml', '/b'), $diagnostics));
        self::assertFalse($versions->ofReferenced($stated, new SchemaLocation('/p/old.yaml', '/c'), $diagnostics));
        self::assertFalse($versions->ofReferenced(new Document('/p/unknown.yaml', []), new SchemaLocation('/p/elsewhere.yaml', '/d'), $diagnostics));
        self::assertTrue($versions->ofReferenced($shared, new SchemaLocation('/p/old.yaml', '/e'), $diagnostics));
        self::assertSame([], $this->messages($diagnostics));

        self::assertTrue($versions->ofReferenced($shared, new SchemaLocation('/p/new.yaml', '/f'), $diagnostics));
        self::assertTrue($versions->ofReferenced($shared, new SchemaLocation('/p/new.yaml', '/g'), $diagnostics));
        self::assertSame(
            ['warning /p/new.yaml#/f: /p/shared.yaml states no "openapi" version and is read as OpenAPI 3.0, the version of the document that referred to it first, though /p/new.yaml, which refers to it here, is 3.1.'],
            $this->messages($diagnostics),
        );
    }

    public function testNamesTheVersionsTheOtherWayRound(): void
    {
        $versions = new Versions();
        $diagnostics = new Diagnostics();
        $versions->ofSource(new Document('/p/old.yaml', ['openapi' => '3.0.3']));
        $shared = new Document('/p/shared.yaml', []);

        self::assertFalse($versions->ofReferenced($shared, new SchemaLocation('/p/new.yaml', '/a'), $diagnostics));
        self::assertFalse($versions->ofReferenced($shared, new SchemaLocation('/p/old.yaml', '/b'), $diagnostics));
        self::assertSame(
            ['warning /p/old.yaml#/b: /p/shared.yaml states no "openapi" version and is read as OpenAPI 3.1, the version of the document that referred to it first, though /p/old.yaml, which refers to it here, is 3.0.'],
            $this->messages($diagnostics),
        );
    }

    /**
     * @return list<string>
     */
    private function messages(Diagnostics $diagnostics): array
    {
        return array_map(static fn (Diagnostic $d): string => $d->toString(), $diagnostics->all());
    }
}
