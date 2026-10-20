<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Presentation\Cli;

use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostic;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Severity;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Path;

/**
 * Shows locations relative to the working directory, as spec §9.2 prints them.
 */
final class DiagnosticFormatter
{
    private string $workingDirectory;

    public function __construct(string $workingDirectory)
    {
        // getcwd() returns backslashes on Windows; locations are already normalised to forward slashes.
        $this->workingDirectory = Path::normalize($workingDirectory) . '/';
    }

    /**
     * A warning that recurs word for word at other places is shown at its first, with how many more there are, unless
     * every place is asked for: Stripe's unknown formats alone are hundreds of lines.
     *
     * @param list<Diagnostic> $diagnostics
     *
     * @return list<string>
     */
    public function lines(array $diagnostics, bool $everyPlace): array
    {
        $shown = [];
        /** @var array<string, int> $first the position of each warning shown */
        $first = [];
        /** @var array<int, int> $more by position, how many more places a warning recurs at */
        $more = [];
        foreach ($diagnostics as $diagnostic) {
            $key = $diagnostic->message();
            $grouped = !$everyPlace && $diagnostic->severity()->value() === Severity::WARNING;
            if ($grouped && isset($first[$key])) {
                $more[$first[$key]] = ($more[$first[$key]] ?? 0) + 1;

                continue;
            }

            if ($grouped) {
                $first[$key] = count($shown);
            }

            $shown[] = $diagnostic;
        }

        return array_map(function (Diagnostic $diagnostic, int $position) use ($more): string {
            $count = $more[$position] ?? 0;

            return $this->line($diagnostic) . ($count === 0 ? '' : sprintf(' (Also at %d more place%s; -v lists them.)', $count, $count === 1 ? '' : 's'));
        }, $shown, array_keys($shown));
    }

    public function line(Diagnostic $diagnostic): string
    {
        return sprintf('%s %s: %s', $diagnostic->severity()->value(), $this->location($diagnostic->location()), $diagnostic->message());
    }

    public function location(SchemaLocation $location): string
    {
        $pointer = $location->pointer();

        return $this->path($location->file()) . ($pointer === '' ? '' : '#' . $pointer);
    }

    public function path(string $path): string
    {
        return strncmp($path, $this->workingDirectory, strlen($this->workingDirectory)) === 0 ? substr($path, strlen($this->workingDirectory)) : $path;
    }
}
