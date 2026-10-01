<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Port;

interface ProjectPhpConstraint
{
    /**
     * The `require.php` constraint of the nearest composer.json at or above $directory, if any.
     */
    public function find(string $directory): ?string;
}
