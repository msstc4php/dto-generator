<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Schema;

/**
 * Where a schema that an `allOf` of a union was distributed into comes from: the schema written in the document, and
 * for each union distributed on the way the member that held it and the variant taken.
 *
 * @api
 */
final class VariantOrigin
{
    /** The segment of a variant's location (`…/x-dto-variant/<member>/<variant>`): no place of the document holds it. */
    public const SEGMENT = 'x-dto-variant';

    private SchemaLocation $root;

    /** @var list<array{SchemaLocation, SchemaLocation, string}> union member, variant taken, its name */
    private array $levels;

    /**
     * @param list<array{SchemaLocation, SchemaLocation, string}> $levels union member, variant taken, its name
     */
    public function __construct(SchemaLocation $root, array $levels = [])
    {
        $this->root = $root;
        $this->levels = $levels;
    }

    /**
     * Whether a schema is a variant that an allOf of a union was distributed into, or within one.
     */
    public static function isVariant(SchemaLocation $location): bool
    {
        return strpos($location->pointer() . '/', '/' . self::SEGMENT . '/') !== false;
    }

    /**
     * The origin of a variant of one more union.
     *
     * @param SchemaLocation $union the member of the allOf that was the union
     * @param SchemaLocation $variant the variant taken, as the union lists it
     * @param string $name the variant's own name: its schema name or title, `Option<N>` without one
     */
    public function withVariant(SchemaLocation $union, SchemaLocation $variant, string $name): self
    {
        return new self($this->root, array_merge($this->levels, [[$union, $variant, $name]]));
    }

    /**
     * The name of the variant taken last; null at the root.
     */
    public function variant(): ?string
    {
        $variants = $this->variants();

        return $variants === [] ? null : $variants[count($variants) - 1];
    }

    /**
     * The names of the variants taken, first union first.
     *
     * @return list<string>
     */
    public function variants(): array
    {
        return array_map(static fn (array $level): string => $level[2], $this->levels);
    }

    /**
     * The names of the variants a part of the class comes with, which every class with them shares: none for a part
     * of the root itself, the first variant's for a part written in it, and so on. Null for a part from elsewhere.
     *
     * @return list<string>|null
     */
    public function variantsOf(SchemaLocation $part): ?array
    {
        if (!$this->isWithin($part, $this->root)) {
            return null;
        }

        $names = [];
        foreach ($this->levels as [$union, $variant, $name]) {
            if (!$this->isWithin($part, $union) || !$this->isWithin($part, $variant)) {
                break;
            }

            $names[] = $name;
        }

        return $names;
    }

    private function isWithin(SchemaLocation $part, SchemaLocation $location): bool
    {
        // The slash keeps `allOf/1` from holding `allOf/10`.
        return $part->file() === $location->file() && strpos($part->pointer() . '/', $location->pointer() . '/') === 0;
    }
}
