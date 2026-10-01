<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Port;

/**
 * What the nearest composer.json says about PHP, and why it says nothing when it cannot be used.
 */
final class PhpRequirement
{
    private ?string $file;

    private ?string $constraint;

    private ?string $problem;

    /**
     * @param string|null $file the composer.json found, null when there is none
     * @param string|null $constraint its "require.php"
     * @param string|null $problem why the file could not be used, e.g. "is not valid JSON"
     */
    public function __construct(?string $file, ?string $constraint, ?string $problem)
    {
        $this->file = $file;
        $this->constraint = $constraint;
        $this->problem = $problem;
    }

    public function file(): ?string
    {
        return $this->file;
    }

    public function constraint(): ?string
    {
        return $this->constraint;
    }

    public function problem(): ?string
    {
        return $this->problem;
    }
}
