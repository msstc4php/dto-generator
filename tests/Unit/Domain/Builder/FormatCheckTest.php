<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Builder\FormatCheck;
use MSSTC4PHP\DtoGenerator\Domain\Builder\SchemaParser;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostic;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use PHPUnit\Framework\TestCase;

final class FormatCheckTest extends TestCase
{
    /**
     * @return iterable<string, array{string, 'string'|'int'|'float'|'bool', list<string>}>
     */
    public static function formats(): iterable
    {
        yield 'known string' => ['email', 'string', []];
        yield 'date as a string' => ['date', 'string', []];
        yield 'unknown string' => ['odd', 'string', ['warning s.yaml#/S/format: Unknown string format "odd"; the property stays a string.']];
        yield 'known integer' => ['int64', 'int', []];
        yield 'unknown integer' => ['date', 'int', ['warning s.yaml#/S/format: Unknown integer format "date"; the property stays an int.']];
        yield 'known number' => ['double', 'float', []];
        yield 'unknown number' => ['odd', 'float', ['warning s.yaml#/S/format: Unknown number format "odd"; the property stays a float.']];
        yield 'a bool takes none' => ['odd', 'bool', []];
    }

    /**
     * @dataProvider formats
     *
     * @param 'string'|'int'|'float'|'bool' $kind
     * @param list<string> $expected
     */
    public function testWarnsAboutFormatsUnknownForTheKind(string $format, string $kind, array $expected): void
    {
        $diagnostics = new Diagnostics();
        $schema = (new SchemaParser())->parse(['format' => $format], new SchemaLocation('s.yaml', '/S'), new Diagnostics());

        FormatCheck::check($schema, $kind, $diagnostics);

        self::assertSame($expected, array_map(static fn (Diagnostic $d): string => $d->toString(), $diagnostics->all()));
    }

    public function testLeavesASchemaWithoutFormatAlone(): void
    {
        $diagnostics = new Diagnostics();
        FormatCheck::check((new SchemaParser())->parse([], new SchemaLocation('s.yaml', '/S'), new Diagnostics()), 'string', $diagnostics);

        self::assertSame([], $diagnostics->all());
    }
}
