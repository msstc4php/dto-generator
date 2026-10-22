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
    public function testTellsOpenApi30FromItsVersion($version, ?string $normalized, bool $openApi30): void
    {
        self::assertSame($normalized, Versions::normalize($version));
        self::assertSame($openApi30 ? Versions::OPENAPI_30 : Versions::OPENAPI_31, (new Versions())->ofSource(new Document('/p/a.yaml', ['openapi' => $version])));
    }

    /**
     * @return iterable<string, array{JsonValue, ?string, bool}>
     */
    public static function versions(): iterable
    {
        yield '3.0.3' => ['3.0.3', '3.0.3', true];
        yield '3.0' => ['3.0', '3.0', true];
        yield 'a float' => [3.0, '3.0', true];
        yield '3.1.0' => ['3.1.0', '3.1.0', false];
        yield '3.0.3 with a suffix' => ['3.0.3-rc', '3.0.3-rc', false];
        yield 'a prefix before 3.0' => ['v3.0', 'v3.0', false];
        yield '3.00' => ['3.00', '3.00', false];
        yield 'an integer' => [3, null, false];
        yield 'none' => [null, null, false];
    }

    /**
     * @dataProvider understood
     */
    public function testTellsWhetherAVersionIsUnderstood(string $version, bool $understood): void
    {
        self::assertSame($understood, Versions::isUnderstood($version));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function understood(): iterable
    {
        yield '3.0.3' => ['3.0.3', true];
        yield '3.0' => ['3.0', true];
        yield '3.1.0' => ['3.1.0', true];
        yield '3.1' => ['3.1', true];
        yield '3.1.0 with a suffix' => ['3.1.0-rc', false];
        yield 'a prefix before 3.1' => ['v3.1', false];
        yield '3.10' => ['3.10', false];
        yield '3.2.0' => ['3.2.0', true];
        yield '3.2' => ['3.2', true];
        yield '3.3.0' => ['3.3.0', false];
        yield '3.20' => ['3.20', false];
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

        self::assertSame(Versions::OPENAPI_30, $versions->ofSource($old));
        self::assertSame(Versions::OPENAPI_31, $versions->ofSource($new));
        self::assertSame(Versions::OPENAPI_30, $versions->ofReferenced($shared, new SchemaLocation('/p/old.yaml', '/a'), $diagnostics));
        self::assertSame(Versions::OPENAPI_30, $versions->ofReferenced($deeper, new SchemaLocation('/p/shared.yaml', '/b'), $diagnostics));
        self::assertSame(Versions::OPENAPI_31, $versions->ofReferenced($stated, new SchemaLocation('/p/old.yaml', '/c'), $diagnostics));
        self::assertSame(Versions::OPENAPI_31, $versions->ofReferenced(new Document('/p/unknown.yaml', []), new SchemaLocation('/p/elsewhere.yaml', '/d'), $diagnostics));
        self::assertSame(Versions::OPENAPI_30, $versions->ofReferenced($shared, new SchemaLocation('/p/old.yaml', '/e'), $diagnostics));
        self::assertSame([], $this->messages($diagnostics));

        self::assertSame(Versions::OPENAPI_30, $versions->ofReferenced($shared, new SchemaLocation('/p/new.yaml', '/f'), $diagnostics));
        self::assertSame(Versions::OPENAPI_30, $versions->ofReferenced($shared, new SchemaLocation('/p/new.yaml', '/g'), $diagnostics));
        self::assertSame(
            ['warning /p/new.yaml#/f: /p/shared.yaml states no "openapi" version and is read as OpenAPI 3.0, the version of the document that referred to it first, though /p/new.yaml, which refers to it here, is OpenAPI 3.1.'],
            $this->messages($diagnostics),
        );
    }

    public function testNamesTheVersionsTheOtherWayRound(): void
    {
        $versions = new Versions();
        $diagnostics = new Diagnostics();
        $versions->ofSource(new Document('/p/old.yaml', ['openapi' => '3.0.3']));
        $shared = new Document('/p/shared.yaml', []);

        self::assertSame(Versions::OPENAPI_31, $versions->ofReferenced($shared, new SchemaLocation('/p/new.yaml', '/a'), $diagnostics));
        self::assertSame(Versions::OPENAPI_31, $versions->ofReferenced($shared, new SchemaLocation('/p/old.yaml', '/b'), $diagnostics));
        self::assertSame(
            ['warning /p/old.yaml#/b: /p/shared.yaml states no "openapi" version and is read as OpenAPI 3.1, the version of the document that referred to it first, though /p/old.yaml, which refers to it here, is OpenAPI 3.0.'],
            $this->messages($diagnostics),
        );
    }

    public function testReadsASwaggerDocumentAndThoseItRefersTo(): void
    {
        $versions = new Versions();
        $diagnostics = new Diagnostics();
        $swagger = new Document('/p/swagger.yaml', ['swagger' => '2.0']);

        self::assertSame(Versions::SWAGGER_20, $versions->ofSource($swagger));
        self::assertSame(Versions::SWAGGER_20, $versions->ofReferenced(new Document('/p/shared.yaml', []), new SchemaLocation('/p/swagger.yaml', '/a'), $diagnostics));
        self::assertSame(Versions::OPENAPI_30, $versions->ofSource(new Document('/p/both.yaml', ['swagger' => '2.0', 'openapi' => '3.0.0'])));
        self::assertSame(Versions::SWAGGER_20, $versions->ofSource(new Document('/p/other.yaml', ['swagger' => '1.2'])));
        self::assertTrue(Versions::isSwagger(['swagger' => '2.0']));
        self::assertFalse(Versions::isSwagger(['swagger' => '2.0', 'openapi' => '3.1.0']));
        self::assertFalse(Versions::isSwagger([]));
        self::assertSame([], $this->messages($diagnostics));
    }

    /**
     * @return list<string>
     */
    private function messages(Diagnostics $diagnostics): array
    {
        return array_map(static fn (Diagnostic $d): string => $d->toString(), $diagnostics->all());
    }
}
