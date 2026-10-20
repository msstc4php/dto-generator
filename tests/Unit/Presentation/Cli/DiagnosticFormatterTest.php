<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Presentation\Cli;

use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostic;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Severity;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use MSSTC4PHP\DtoGenerator\Presentation\Cli\DiagnosticFormatter;
use PHPUnit\Framework\TestCase;

final class DiagnosticFormatterTest extends TestCase
{
    public function testShowsPathsRelativeToTheWorkingDirectory(): void
    {
        $formatter = new DiagnosticFormatter('/project/');

        self::assertSame('api/openapi.yaml', $formatter->path('/project/api/openapi.yaml'));
        self::assertSame('/elsewhere/x.yaml', $formatter->path('/elsewhere/x.yaml'));
        self::assertSame('/projectx/a.yaml', $formatter->path('/projectx/a.yaml'));
        self::assertSame('dto-generator.yaml', $formatter->location(new SchemaLocation('/project/dto-generator.yaml')));
        self::assertSame(
            'warning api/openapi.yaml#/components/schemas/User: Careful.',
            $formatter->line(new Diagnostic(Severity::from(Severity::WARNING), 'Careful.', new SchemaLocation('/project/api/openapi.yaml', '/components/schemas/User'))),
        );
    }

    public function testShowsAWarningThatRecursAtItsFirstPlace(): void
    {
        $formatter = new DiagnosticFormatter('/p/');
        $warning = static fn (string $message, string $at): Diagnostic => new Diagnostic(Severity::from(Severity::WARNING), $message, new SchemaLocation('/p/a.yaml', $at));
        $error = static fn (string $message, string $at): Diagnostic => new Diagnostic(Severity::from(Severity::ERROR), $message, new SchemaLocation('/p/a.yaml', $at));
        $diagnostics = [
            $warning('Odd format.', '/A'),
            $error('Odd format.', '/B'),
            $warning('5', '/C'),
            $warning('Odd format.', '/D'),
            $error('Odd format.', '/E'),
            $warning('Once.', '/F'),
            $warning('Odd format.', '/G'),
            $warning('5', '/H'),
        ];

        self::assertSame(
            [
                'warning a.yaml#/A: Odd format. (Also at 2 more places; -v lists them.)',
                'error a.yaml#/B: Odd format.',
                'warning a.yaml#/C: 5 (Also at 1 more place; -v lists them.)',
                'error a.yaml#/E: Odd format.',
                'warning a.yaml#/F: Once.',
            ],
            $formatter->lines($diagnostics, false),
        );
        self::assertSame(array_map([$formatter, 'line'], $diagnostics), $formatter->lines($diagnostics, true));
    }

    public function testNormalisesAWindowsWorkingDirectory(): void
    {
        self::assertSame('api/openapi.yaml', (new DiagnosticFormatter('C:\\proj'))->path('C:/proj/api/openapi.yaml'));
    }
}
