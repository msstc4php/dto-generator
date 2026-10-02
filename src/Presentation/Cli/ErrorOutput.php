<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Presentation\Cli;

use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class ErrorOutput
{
    private function __construct()
    {
    }

    /**
     * Stderr of a console, or the output itself when it has none (a buffer, a test).
     */
    public static function of(OutputInterface $output): OutputInterface
    {
        return $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
    }

    /**
     * The JSON report of a run that failed before generating: the same shape as a full report.
     */
    public static function json(string $message): string
    {
        return self::encode(['status' => 'config-failed', 'diagnostics' => [['severity' => 'error', 'location' => '', 'message' => $message]], 'changes' => []]);
    }

    /**
     * Readable as is: pretty-printed, with slashes and non-ASCII characters left alone.
     *
     * @param array{status: string, diagnostics: list<array{severity: string, location: string, message: string}>, changes: list<array{kind: string, path: string}>} $report
     */
    public static function encode(array $report): string
    {
        return (string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
