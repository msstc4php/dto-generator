<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Discriminator;
use MSSTC4PHP\DtoGenerator\Domain\Schema\ResolvedSchema;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaGraph;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;

/**
 * Drops the discriminator of a union that only restricts the properties of its own schema: inline variants that declare
 * nothing beyond them, one of which allows several values of the discriminating property, give no class the
 * discriminator could name, and the schema is a class of its properties. Only the schemas of the graph are looked at.
 *
 * @phpstan-import-type JsonValue from Json
 */
final class UnusableDiscriminators
{
    /** Keywords beside the properties of a variant that restrict nothing the class holds. */
    private const ANNOTATIONS = ['title', '$comment', 'example', 'examples', 'readOnly', 'writeOnly', 'minProperties', 'maxProperties'];

    private function __construct()
    {
    }

    public static function of(SchemaGraph $graph, Diagnostics $diagnostics): SchemaGraph
    {
        $changed = [];
        foreach ($graph->all() as $resolved) {
            $schema = $resolved->schema();
            $discriminator = SchemaShape::discriminatorOf($schema);
            $reason = $discriminator instanceof Discriminator ? self::reason($schema, $discriminator) : null;
            if ($reason === null) {
                continue;
            }

            $diagnostics->warning(sprintf('The discriminator is ignored, as %s; the class keeps the properties.', $reason), $schema->location()->child('discriminator'));
            $changed[] = new ResolvedSchema($schema->withoutDiscriminator(), $resolved->source(), $resolved->name(), $resolved->isSelected(), $resolved->isNamedByPlace());
        }

        return $changed === [] ? $graph : $graph->with($changed);
    }

    private static function reason(Schema $schema, Discriminator $discriminator): ?string
    {
        // A mapping names classes; the build reports what it cannot use.
        $own = $schema->propertyNames();
        if ($own === [] || $discriminator->values() !== [] || $discriminator->defaultRef() !== null) {
            return null;
        }

        $several = null;
        foreach (['oneOf' => $schema->oneOf(), 'anyOf' => $schema->anyOf()] as $keyword => $members) {
            foreach ($members as $index => $member) {
                if (!self::onlyRestricts($member, $own)) {
                    return null;
                }

                if ($several === null && self::allowsSeveral($member, $discriminator->propertyName())) {
                    $several = $keyword . '/' . $index;
                }
            }
        }

        return $several === null ? null : sprintf('its inline variants only restrict the properties of the schema, and %s allows several values of "%s"', $several, $discriminator->propertyName());
    }

    /**
     * An inline object that says nothing but what its properties, a subset of the schema's, may hold and which it
     * requires: anything more is the build's to read, or to report.
     *
     * @param list<string> $own the properties of the schema
     */
    private static function onlyRestricts(Schema $member, array $own): bool
    {
        $keywords = array_diff(array_keys($member->keywords()), self::ANNOTATIONS);

        return $member->ref() === null && SchemaShape::mayBeObject($member) && $member->enum() === null
            && $member->allOf() === [] && !SchemaShape::hasUnion($member) && !$member->discriminator() instanceof Discriminator
            && !$member->items() instanceof Schema && !$member->additionalProperties() instanceof Schema
            && $keywords === [] && array_diff($member->propertyNames(), $own) === [];
    }

    /**
     * Only the plain case: a property with no const and an enum of several values, or none at all.
     */
    private static function allowsSeveral(Schema $variant, string $property): bool
    {
        $declaration = $variant->property($property);
        if (!$declaration instanceof Schema) {
            return true;
        }

        if ($declaration->hasKeyword('const') || $declaration->ref() !== null || $declaration->allOf() !== [] || SchemaShape::hasUnion($declaration)) {
            return false;
        }

        $enum = $declaration->enum();

        return $enum === null || count(array_filter($enum, /** @param JsonValue $value */ static fn ($value): bool => $value !== null)) > 1;
    }
}
