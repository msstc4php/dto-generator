<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Presentation\Cli;

/**
 * The machine-readable report of `--format=json`.
 *
 * Status and severity carry the values of the Status and Severity enums.
 *
 * @phpstan-type DiagnosticShape array{severity: string, location: string, message: string}
 * @phpstan-type ChangeShape array{kind: 'create'|'update'|'delete'|'unchanged', path: string}
 * @phpstan-type ReportShape array{status: string, diagnostics: list<DiagnosticShape>, changes: list<ChangeShape>}
 */
final class JsonReport
{
    private function __construct()
    {
    }

    /**
     * A run that failed before generating, in the same shape as a full report.
     */
    public static function failure(string $message): string
    {
        return self::encode(['status' => 'config-failed', 'diagnostics' => [['severity' => 'error', 'location' => '', 'message' => $message]], 'changes' => []]);
    }

    /**
     * Readable as is, and never empty: invalid UTF-8 (a Latin-1 path) is replaced rather than failing the encoding.
     *
     * @param ReportShape $report
     */
    public static function encode(array $report): string
    {
        return (string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
