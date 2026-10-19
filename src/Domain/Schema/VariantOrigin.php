<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Schema;

/**
 * Where a schema that an `allOf` of a union was distributed into comes from: the schema written in the document, the
 * name the classes of the parts every variant shares start with, and the members of its `allOf` that were unions.
 *
 * @api
 */
final class VariantOrigin
{
    /** The segment of a variant's location (`…/x-dto-variant/<member>/<variant>`): no place of the document holds it. */
    public const SEGMENT = 'x-dto-variant';

    private SchemaLocation $root;

    private string $base;

    /** @var list<SchemaLocation> */
    private array $unions;

    /**
     * @param list<SchemaLocation> $unions
     */
    public function __construct(SchemaLocation $root, string $base, array $unions = [])
    {
        $this->root = $root;
        $this->base = $base;
        $this->unions = $unions;
    }

    /**
     * Whether a schema is a variant that an allOf of a union was distributed into, or within one.
     */
    public static function isVariant(SchemaLocation $location): bool
    {
        return strpos($location->pointer() . '/', '/' . self::SEGMENT . '/') !== false;
    }

    public function withUnion(SchemaLocation $union): self
    {
        return new self($this->root, $this->base, array_merge($this->unions, [$union]));
    }

    public function base(): string
    {
        return $this->base;
    }

    /**
     * Whether a part of the variant's class is the root's own, not its variant's: every variant shares it.
     */
    public function shares(SchemaLocation $part): bool
    {
        if ($part->file() !== $this->root->file() || !$this->isWithin($part, $this->root)) {
            return false;
        }

        foreach ($this->unions as $union) {
            if ($this->isWithin($part, $union)) {
                return false;
            }
        }

        return true;
    }

    private function isWithin(SchemaLocation $part, SchemaLocation $location): bool
    {
        // The slash keeps `allOf/1` from holding `allOf/10`.
        return strpos($part->pointer() . '/', $location->pointer() . '/') === 0;
    }
}
