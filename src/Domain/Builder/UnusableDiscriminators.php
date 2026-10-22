<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Discriminator;
use MSSTC4PHP\DtoGenerator\Domain\Schema\ResolvedSchema;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaGraph;

/**
 * Drops the discriminator of a union that only restricts the properties of its own schema: inline variants that declare
 * nothing beyond them, one of which allows several values of the discriminating property, give no class the
 * discriminator could name, and the schema is a class of its properties. Only the schemas of the graph are looked at.
 */
final class UnusableDiscriminators
{
    private function __construct()
    {
    }

    public static function of(SchemaGraph $graph, Diagnostics $diagnostics): SchemaGraph
    {
        $changed = [];
        foreach ($graph->all() as $resolved) {
            $schema = $resolved->schema();
            $discriminator = SchemaShape::discriminatorOf($schema);
            $reason = $discriminator instanceof Discriminator ? self::reason($schema, $discriminator->propertyName()) : null;
            if ($reason === null) {
                continue;
            }

            $diagnostics->warning(sprintf('The discriminator is ignored, as %s; the class keeps the properties.', $reason), $schema->location()->child('discriminator'));
            $changed[] = new ResolvedSchema($schema->withoutDiscriminator(), $resolved->source(), $resolved->name(), $resolved->isSelected(), $resolved->isNamedByPlace());
        }

        return $changed === [] ? $graph : $graph->with($changed);
    }

    private static function reason(Schema $schema, string $property): ?string
    {
        $own = $schema->propertyNames();
        $several = null;
        foreach (array_merge($schema->oneOf(), $schema->anyOf()) as $index => $member) {
            if ($member->ref() !== null || !SchemaShape::mayBeObject($member) || $member->allOf() !== [] || array_diff($member->propertyNames(), $own) !== []) {
                return null;
            }

            if ($several === null && self::allowsSeveral($member, $property)) {
                $several = $index + 1;
            }
        }

        return $own === [] || $several === null ? null : sprintf('its inline variants only restrict the properties of the schema, and variant %d allows several values of "%s"', $several, $property);
    }

    /**
     * Only the plain case: a property with no const and an enum of several values, or none at all.
     */
    private static function allowsSeveral(Schema $variant, string $property): bool
    {
        if ($variant->allOf() !== []) {
            return false;
        }

        $declaration = $variant->property($property);
        if (!$declaration instanceof Schema) {
            return true;
        }

        if ($declaration->hasKeyword('const') || $declaration->ref() !== null || $declaration->allOf() !== [] || SchemaShape::hasUnion($declaration)) {
            return false;
        }

        $values = array_filter($declaration->enum() ?? [], static fn ($value): bool => $value !== null);

        return count($values) !== 1;
    }
}
