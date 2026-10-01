<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Support;

use MSSTC4PHP\DtoGenerator\Application\Port\PhpRequirement;
use MSSTC4PHP\DtoGenerator\Application\Port\ProjectPhpConstraint;

final class FixedPhpConstraint implements ProjectPhpConstraint
{
    private ?string $constraint;

    private ?string $problem;

    /** @var list<string> */
    private array $directories = [];

    public function __construct(?string $constraint, ?string $problem = null)
    {
        $this->constraint = $constraint;
        $this->problem = $problem;
    }

    public function find(string $directory): PhpRequirement
    {
        $this->directories[] = $directory;

        $file = $directory . '/composer.json';

        return $this->problem === null ? PhpRequirement::found($file, $this->constraint) : PhpRequirement::unusable($file, $this->problem);
    }

    /**
     * @return list<string>
     */
    public function directories(): array
    {
        return $this->directories;
    }
}
