<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Schema;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;

/**
 * @api
 */
final class ResolvedSchema
{
    private Schema $schema;

    private ?int $source;

    private string $name;

    private bool $selected;

    private bool $namedByPlace;

    /**
     * @param int|null $source index of the owning config source; null while its file belongs to none
     * @param string $name component name, or the last pointer segment / file name for other targets
     * @param bool $selected picked by a source's include/exclude filter rather than only referenced
     * @param bool $namedByPlace the name tells where the schema is (an operation's request body or response), as an
     *                           inline schema's does, so another schema may hold it
     */
    public function __construct(Schema $schema, ?int $source, string $name, bool $selected, bool $namedByPlace = false)
    {
        if ($name === '') {
            throw new InvalidModel(sprintf('Resolved schema %s needs a name.', $schema->location()->toString()));
        }

        $this->schema = $schema;
        $this->source = $source;
        $this->name = $name;
        $this->selected = $selected;
        $this->namedByPlace = $namedByPlace;
    }

    public function schema(): Schema
    {
        return $this->schema;
    }

    public function location(): SchemaLocation
    {
        return $this->schema->location();
    }

    public function source(): ?int
    {
        return $this->source;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function isSelected(): bool
    {
        return $this->selected;
    }

    public function isNamedByPlace(): bool
    {
        return $this->namedByPlace;
    }

    public function withSource(int $source): self
    {
        return new self($this->schema, $source, $this->name, $this->selected, $this->namedByPlace);
    }
}
