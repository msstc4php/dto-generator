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

    private function __construct(?string $file, ?string $constraint, ?string $problem)
    {
        $this->file = $file;
        $this->constraint = $constraint;
        $this->problem = $problem;
    }

    public static function none(): self
    {
        return new self(null, null, null);
    }

    /**
     * @param string|null $constraint its "require.php", null when it does not pin PHP
     */
    public static function found(string $file, ?string $constraint): self
    {
        return new self($file, $constraint, null);
    }

    /**
     * @param string $problem why the file cannot be used, e.g. "is not valid JSON"
     */
    public static function unusable(string $file, string $problem): self
    {
        return new self($file, null, $problem);
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

    /**
     * "<file> <problem>" when the file could not be used.
     */
    public function problemDescription(): ?string
    {
        return $this->file === null || $this->problem === null ? null : $this->file . ' ' . $this->problem;
    }
}
