<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Service\Model\Build;

use MSSTC4PHP\DtoGenerator\Domain\Builder\Composition;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;

/**
 * An object schema a build met, with its composition; one refused its class name, or sharing the class of an identical
 * schema, knows the schema holding that name.
 */
final class ClassSchema
{
    private Schema $schema;

    private Composition $composition;

    private ?string $taker;

    /**
     * @param string|null $taker location of the schema holding the name, for a schema refused it or sharing its class
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
