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

    public function testNormalisesAWindowsWorkingDirectory(): void
    {
        self::assertSame('api/openapi.yaml', (new DiagnosticFormatter('C:\\proj'))->path('C:/proj/api/openapi.yaml'));
    }
}
