<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Port;

interface ProjectPhpConstraint
{
    /**
     * The `require.php` of the nearest composer.json at or above $directory.
     */
    public function find(string $directory): PhpRequirement;
}
