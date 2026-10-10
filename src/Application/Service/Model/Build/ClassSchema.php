<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Service\Model\Build;

use MSSTC4PHP\DtoGenerator\Domain\Builder\Composition;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;

/**
 * An object schema a build met, with its composition; one whose class name another schema took knows that schema.
 */
final class ClassSchema
{
    private Schema $schema;

    private Composition $composition;

    private ?string $taker;

    /**
     * @param string|null $taker location of the schema that took the name, for a schema that did not get it
     */
    public function __construct(Schema $schema, Composition $composition, ?string $taker = null)
    {
        $this->schema = $schema;
        $this->composition = $composition;
        $this->taker = $taker;
    }

    public function schema(): Schema
    {
        return $this->schema;
    }

    public function composition(): Composition
    {
        return $this->composition;
    }

    public function taker(): ?string
    {
        return $this->taker;
    }
}
