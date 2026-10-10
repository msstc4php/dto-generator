<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Schema\ReferenceUse;
use MSSTC4PHP\DtoGenerator\Domain\Schema\ResolvedSchema;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaGraph;

/**
 * The direction of a property (spec F1 §3): readOnly or writeOnly on its schema or anywhere along its $ref chain, as
 * a referenced `{readOnly: true}` schema marks every property holding it.
 */
final class Directions
{
    private function __construct()
    {
    }

    /**
     * The only view the property belongs to; null when it belongs to both.
     */
    public static function of(Schema $property, SchemaGraph $graph, Diagnostics $diagnostics): ?Direction
    {
        $read = false;
        $write = false;
        foreach (self::chain($property, $graph) as $schema) {
            $read = self::flag($schema, 'readOnly', $diagnostics) || $read;
            $write = self::flag($schema, 'writeOnly', $diagnostics) || $write;
        }

        if ($read && $write) {
            $diagnostics->warning('"readOnly" and "writeOnly" are both true; the property is in both views.', $property->location());

            return null;
        }

        if ($read) {
            return Direction::from(Direction::READ);
        }

        return $write ? Direction::from(Direction::WRITE) : null;
    }

    /**
     * @param 'readOnly'|'writeOnly' $keyword
     */
    private static function flag(Schema $schema, string $keyword, Diagnostics $diagnostics): bool
    {
        if (!$schema->hasKeyword($keyword)) {
            return false;
        }

        $value = $schema->keyword($keyword);
        if (!is_bool($value)) {
            $diagnostics->warning(sprintf('"%s" must be true or false; it is ignored.', $keyword), $schema->location()->child($keyword));

            return false;
        }

        return $value;
    }

    /**
     * The schema and every schema its $ref chain passes through, each once.
     *
     * @return non-empty-list<Schema>
     */
    private static function chain(Schema $schema, SchemaGraph $graph): array
    {
        $chain = [$schema];
        for ($current = $schema; $current->ref() !== null; $current = $next) {
            $target = $graph->resolve(new ReferenceUse($current->ref(), $current->location()));
            if (!$target instanceof ResolvedSchema || self::listed($target->schema(), $chain)) {
                break;
            }

            $next = $target->schema();
            $chain[] = $next;
        }

        return $chain;
    }

    /**
     * @param list<Schema> $chain
     */
    private static function listed(Schema $schema, array $chain): bool
    {
        foreach ($chain as $listed) {
            if ($listed->location()->toString() === $schema->location()->toString()) {
                return true;
            }
        }

        return false;
    }
}
