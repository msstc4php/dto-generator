<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Service\Generate;

use InvalidArgumentException;

/**
 * One emitted PHP file, addressed inside the output directory of its source.
 *
 * @api
 */
final class GeneratedFile
{
    private string $outputDir;

    private string $relativePath;

    private string $contents;

    private ?string $className;

    /**
     * @param string|null $className the fully qualified name of the class the file declares; it does not
     *                               exist before the file is loaded
     */
    public function __construct(string $outputDir, string $relativePath, string $contents, ?string $className = null)
    {
        if (!self::isSafeRelativePath($relativePath)) {
            throw new InvalidArgumentException(sprintf('"%s" must be a relative path inside the output directory.', $relativePath));
        }

        $this->outputDir = $outputDir;
        $this->relativePath = $relativePath;
        $this->contents = $contents;
        $this->className = $className;
    }

    public function className(): ?string
    {
        return $this->className;
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

    /**
     * A forward-slash path that cannot leave the directory it is resolved against, on any platform.
     */
    public static function isSafeRelativePath(string $path): bool
    {
        // Valid UTF-8 too, so the path survives the JSON manifest unchanged.
        return $path !== ''
            && preg_match('//u', $path) === 1
            && $path[0] !== '/'
            && strpbrk($path, "\\:\0") === false
            && !in_array('..', explode('/', $path), true);
    }
}
