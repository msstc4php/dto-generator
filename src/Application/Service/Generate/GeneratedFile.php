<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Service\Generate;

use InvalidArgumentException;

/**
 * One emitted PHP file, addressed inside the output directory of its source.
 */
final class GeneratedFile
{
    private string $outputDir;

    private string $relativePath;

    private string $contents;

    public function __construct(string $outputDir, string $relativePath, string $contents)
    {
        if ($relativePath === '' || $relativePath[0] === '/' || in_array('..', explode('/', $relativePath), true)) {
            throw new InvalidArgumentException(sprintf('"%s" must be a relative path inside the output directory.', $relativePath));
        }

        $this->outputDir = $outputDir;
        $this->relativePath = $relativePath;
        $this->contents = $contents;
    }

    public function outputDir(): string
    {
        return $this->outputDir;
    }

    public function relativePath(): string
    {
        return $this->relativePath;
    }

    public function path(): string
    {
        return rtrim($this->outputDir, '/') . '/' . $this->relativePath;
    }

    public function contents(): string
    {
        return $this->contents;
    }
}
