<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaGraph;

/**
 * The direction of properties (spec F1 §3): readOnly or writeOnly on a property's schema or anywhere along its $ref
 * chain, as a referenced `{readOnly: true}` schema marks every property holding it. One instance serves one build: the
 * flags of a schema are read, and reported, once.
 */
final class Directions
{
    private SchemaGraph $graph;

    private Diagnostics $diagnostics;

    /** @var array<string, array{bool, bool}> location → readOnly, writeOnly of the schema and its chain */
    private array $flags = [];

    public function __construct(SchemaGraph $graph, Diagnostics $diagnostics)
    {
        $this->graph = $graph;
        $this->diagnostics = $diagnostics;
    }

    /**
     * The only view a property belongs to; null when it belongs to both.
     */
    public function of(Schema $property): ?Direction
    {
        $directed = $this->ofSources([['', $property]]);

        return $directed === [] ? null : $directed[0][1];
    }

    /**
     * The directed properties of a class, from every member of its composition that declares them: a flag on any one
     * of them counts, and readOnly in one with writeOnly in another is both.
     *
     * @param list<array{string, Schema}> $sources wire name and schema of each declaration
     *
     * @return list<array{string, Direction}> wire name and the only view the property belongs to, of each directed one
     */
    public function ofSources(array $sources): array
    {
        /** @var array<array-key, array{string, bool, bool, Schema}> $properties wire names may be numeric keys */
        $properties = [];
        foreach ($sources as [$wireName, $schema]) {
            [$read, $write] = $this->flagsOf($schema);
            [, $readBefore, $writeBefore, $where] = $properties[$wireName] ?? [$wireName, false, false, $schema];
            // Reported where the second flag comes in: the place moves with each declaration until both are set.
            $properties[$wireName] = [$wireName, $readBefore || $read, $writeBefore || $write, $readBefore && $writeBefore ? $where : $schema];
        }

        $directed = [];
        foreach ($properties as [$wireName, $read, $write, $where]) {
            if ($read && $write) {
                $this->diagnostics->warning('"readOnly" and "writeOnly" are both true; the property is in both views.', $where->location());
            } elseif ($read || $write) {
                $directed[] = [$wireName, Direction::from($read ? Direction::READ : Direction::WRITE)];
            }
        }

        return $directed;
    }

    /**
     * @return array{bool, bool}
     */
    private function flagsOf(Schema $schema): array
    {
        $key = $schema->location()->toString();
        if (!array_key_exists($key, $this->flags)) {
            $read = false;
            $write = false;
            foreach ($this->graph->chain($schema) as $link) {
                $read = $this->flag($link, 'readOnly') || $read;
                $write = $this->flag($link, 'writeOnly') || $write;
            }

            $this->flags[$key] = [$read, $write];
        }

        return $this->flags[$key];
    }

    /**
     * @param 'readOnly'|'writeOnly' $keyword
     */
    private function flag(Schema $schema, string $keyword): bool
    {
        if (!$schema->hasKeyword($keyword)) {
            return false;
        }

        $value = $schema->keyword($keyword);
        if (!is_bool($value)) {
            $this->diagnostics->warning(sprintf('"%s" must be true or false; it is ignored.', $keyword), $schema->location()->child($keyword));

            return false;
        }

        return $value;
    }
}
