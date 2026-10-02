<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Extension\CustomAttributes;

use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use RuntimeException;

/**
 * A mistake in an attribute declaration; the whole attribute is left out.
 */
final class GrammarError extends RuntimeException
{
    private SchemaLocation $location;

    public function __construct(string $message, SchemaLocation $location)
    {
        parent::__construct($message);
        $this->location = $location;
    }

    public function location(): SchemaLocation
    {
        return $this->location;
    }
}
