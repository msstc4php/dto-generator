<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Schema;

/**
 * One union a variant was distributed over: the member of the `allOf` that held it, the variant taken and its name.
 *
 * @api
 */
final class VariantLevel
{
    private SchemaLocation $union;

    private SchemaLocation $variant;

    /** @var non-empty-string */
    private string $name;

    /**
     * @param non-empty-string $name the variant's own name: its schema name or title, `Option<N>` without one
     */
    public function __construct(SchemaLocation $union, SchemaLocation $variant, string $name)
    {
        $this->union = $union;
        $this->variant = $variant;
        $this->name = $name;
    }

    public function union(): SchemaLocation
    {
        return $this->union;
    }

    public function variant(): SchemaLocation
    {
        return $this->variant;
    }

    /**
     * @return non-empty-string
     */
    public function name(): string
    {
        return $this->name;
    }
}
