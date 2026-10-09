<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Schema;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;

/**
 * A `$ref` together with the place it was written, for resolution and for diagnostics.
 *
 * @api
 */
final class ReferenceUse
{
    private string $ref;

    private SchemaLocation $location;

    public function __construct(string $ref, SchemaLocation $location)
    {
        if ($ref === '') {
            throw new InvalidModel('A reference needs a target.');
        }

        $this->ref = $ref;
        $this->location = $location;
    }

    public function ref(): string
    {
        return $this->ref;
    }

    public function location(): SchemaLocation
    {
        return $this->location;
    }

    /**
     * Identity of this use: the same $ref text written at the same place.
     */
    public function key(): string
    {
        return $this->location->toString() . "\0" . $this->ref;
    }
}
