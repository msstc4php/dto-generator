<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Presentation\Cli;

use MSSTC4PHP\DtoGenerator\Presentation\Cli\JsonReport;
use PHPUnit\Framework\TestCase;

final class JsonReportTest extends TestCase
{
    public function testDescribesAFailureBeforeGenerating(): void
    {
        self::assertSame(
            ['status' => 'config-failed', 'diagnostics' => [['severity' => 'error', 'location' => '', 'message' => 'No config.']], 'changes' => []],
            json_decode(JsonReport::failure('No config.'), true),
        );
    }

    public function testSurvivesInvalidUtf8(): void
    {
        $report = json_decode(JsonReport::failure("bad \xFF"), true);

        self::assertIsArray($report);
        self::assertSame('config-failed', $report['status']);
    }
}
