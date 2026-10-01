<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Support;

use MSSTC4PHP\DtoGenerator\Application\Port\ProjectPhpConstraint;

final class FixedPhpConstraint implements ProjectPhpConstraint
{
    private ?string $constraint;

    public function __construct(?string $constraint)
    {
        $this->constraint = $constraint;
    }

    public function find(string $directory): ?string
    {
        return $this->constraint;
    }
}
