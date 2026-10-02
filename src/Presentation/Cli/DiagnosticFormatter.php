<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Presentation\Cli;

use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostic;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;

/**
 * Shows locations relative to the working directory, as spec §9.2 prints them.
 */
final class DiagnosticFormatter
{
    private string $workingDirectory;

    public function __construct(string $workingDirectory)
    {
        $this->workingDirectory = rtrim($workingDirectory, '/') . '/';
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
