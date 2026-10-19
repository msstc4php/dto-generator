<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Schema\Discriminator;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Extensions;
use MSSTC4PHP\DtoGenerator\Domain\Schema\ReferenceUse;
use MSSTC4PHP\DtoGenerator\Domain\Schema\ResolvedSchema;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaGraph;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaType;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;

/**
 * Writes an object whose `allOf` has a union of objects as the union of that object with each variant:
 * `allOf: [anyOf: [A, B], E]` is `anyOf: [allOf: [A, E], allOf: [B, E]]`, as for `oneOf`. Each of those is a class, which
 * no union of variants could be. A union with a discriminator, of anything but objects, or two unions in one `allOf`
 * stay as written, which the build reports.
 *
 * @phpstan-import-type JsonValue from Json
 */
final class UnionDistribution
{
    /** Where a variant written with the rest of the `allOf` lives: no place of the document holds it. */
    public const SEGMENT = 'x-dto-variant';

    private SchemaGraph $graph;

    /** @var list<ResolvedSchema> the variants of named schemas, named themselves */
    private array $added = [];

    /** @var array<string, string> the references to them */
    private array $edges = [];

    private function __construct(SchemaGraph $graph)
    {
        $this->graph = $graph;
    }

    public static function of(SchemaGraph $graph): SchemaGraph
    {
        $counter = count($graph->all());
        // An allOf that names a distributed schema distributes in the next round; each round removes one level.
        for ($round = 0; $round <= $counter; $round++) {
            $distribution = new self($graph);
            $changed = [];
            foreach ($graph->all() as $resolved) {
                $schema = $distribution->rewrite($resolved->schema(), $resolved);
                if ($schema !== $resolved->schema()) {
                    $changed[] = new ResolvedSchema($schema, $resolved->source(), $resolved->name(), $resolved->isSelected());
                }
            }

            if ($changed === []) {
                break;
            }

            $graph = $graph->with(array_merge($changed, $distribution->added), $distribution->edges);
        }

        return $graph;
    }

    private function rewrite(Schema $schema, ?ResolvedSchema $named): Schema
    {
        $properties = $this->properties($schema);
        $rewrittenProperties = array_map(fn (Schema $property): Schema => $this->rewrite($property, null), $properties);
        $items = $schema->items();
        $rewrittenItems = $items instanceof Schema ? $this->rewrite($items, null) : null;
        $additional = $schema->additionalProperties();
        $rewrittenAdditional = $additional instanceof Schema ? $this->rewrite($additional, null) : $additional;
        $allOf = $this->each($schema->allOf());
        $oneOf = $this->each($schema->oneOf());
        $anyOf = $this->each($schema->anyOf());
        if ($rewrittenProperties !== $properties || $rewrittenItems !== $items || $rewrittenAdditional !== $additional
            || $allOf !== $schema->allOf() || $oneOf !== $schema->oneOf() || $anyOf !== $schema->anyOf()) {
            $schema = $this->schema($schema, $schema->location(), $schema->types(), $rewrittenProperties, $schema->required(), $rewrittenItems, $rewrittenAdditional, $allOf, $oneOf, $anyOf, $schema->keywords(), $schema->extensions());
        }

        return $this->distributed($schema, $named) ?? $schema;
    }

    /**
     * @param list<Schema> $schemas
     *
     * @return list<Schema>
     */
    private function each(array $schemas): array
    {
        return array_map(fn (Schema $schema): Schema => $this->rewrite($schema, null), $schemas);
    }

    /**
     * @param ResolvedSchema|null $named the graph entry of a named schema, whose variants are named schemas too; inline ones
     *                                   take the names of union members
     */
    private function distributed(Schema $schema, ?ResolvedSchema $named): ?Schema
    {
        if (!SchemaShape::mayBeObject($schema) || SchemaShape::hasUnion($schema) || $schema->discriminator() instanceof Discriminator) {
            return null;
        }

        $unions = [];
        foreach ($schema->allOf() as $index => $member) {
            $union = $this->unionOf($member);
            if ($union !== null) {
                $unions[$index] = $union;
            }
        }

        if (count($unions) !== 1) {
            return null;
        }

        $index = array_key_first($unions);
        [$kind, $variants] = $unions[$index];
        $extensions = $this->withoutClassName($schema->extensions());
        $members = [];
        foreach ($variants as $position => $variant) {
            $allOf = array_merge(array_slice($schema->allOf(), 0, $index), [$variant], array_slice($schema->allOf(), $index + 1));
            $keywords = $schema->keywords();
            unset($keywords['title']);
            $location = $schema->location()->child(self::SEGMENT, (string) $position);
            $member = $this->schema($schema, $location, $schema->nonNullTypes(), $this->properties($schema), $schema->required(), $schema->items(), $schema->additionalProperties(), $allOf, [], [], $keywords, $extensions);
            $members[] = $named instanceof ResolvedSchema ? $this->named($member, $named, $this->nameOf($variant) ?? 'Option' . ($position + 1), $kind, $position) : $member;
        }

        return $this->schema($schema, $schema->location(), $schema->types(), [], [], null, null, [], $kind === 'oneOf' ? $members : [], $kind === 'anyOf' ? $members : [], [], $extensions);
    }

    /**
     * The variants of a union of objects without a discriminator, written in the member or behind its `$ref`.
     *
     * @return array{'oneOf'|'anyOf', list<Schema>}|null
     */
    private function unionOf(Schema $member): ?array
    {
        $chain = $this->graph->chain($member);
        $union = $chain[count($chain) - 1];
        if (!SchemaShape::mayBeObject($union) || $union->allOf() !== [] || $union->discriminator() !== null
            || $union->propertyNames() !== [] || $union->required() !== [] || $union->additionalProperties() !== null
            || ($union->oneOf() === []) === ($union->anyOf() === [])) {
            return null;
        }

        $kind = $union->oneOf() !== [] ? 'oneOf' : 'anyOf';
        $variants = $kind === 'oneOf' ? $union->oneOf() : $union->anyOf();
        foreach ($variants as $variant) {
            $chain = $this->graph->chain($variant);
            if (!SchemaShape::mayBeObject($chain[count($chain) - 1]) || SchemaShape::isEnum($chain[count($chain) - 1])) {
                return null;
            }
        }

        return [$kind, $variants];
    }

    /**
     * A variant of a named schema is a named schema of its own, `<Name> <Variant>`, so an allOf naming it extends it.
     *
     * @param 'oneOf'|'anyOf' $kind
     */
    private function named(Schema $variant, ResolvedSchema $owner, string $variantName, string $kind, int $position): Schema
    {
        $className = $owner->schema()->extensions()->has('x-php-class-name') ? $owner->schema()->extensions()->get('x-php-class-name') : null;
        $this->added[] = new ResolvedSchema($variant, $owner->source(), (is_string($className) ? $className : $owner->name()) . ' ' . $variantName, $owner->isSelected());
        $ref = $variant->location()->toString();
        $at = $owner->location()->child($kind, (string) $position);
        $this->edges[(new ReferenceUse($ref, $at))->key()] = $variant->location()->toString();

        return new Schema($at, [], $ref, null, null, $owner->schema()->isDeprecated(), null, null, [], [], null, null, [], [], [], null, [], new Extensions());
    }

    private function nameOf(Schema $variant): ?string
    {
        $ref = $variant->ref();
        if ($ref !== null) {
            $target = $this->graph->resolve(new ReferenceUse($ref, $variant->location()));

            return $target instanceof ResolvedSchema ? $target->name() : null;
        }

        $title = $variant->hasKeyword('title') ? $variant->keyword('title') : null;

        return is_string($title) ? $title : null;
    }

    private function withoutClassName(Extensions $extensions): Extensions
    {
        $values = $extensions->all();
        unset($values['x-php-class-name']);

        return new Extensions($values);
    }

    /**
     * @return array<int|string, Schema>
     */
    private function properties(Schema $schema): array
    {
        $properties = [];
        foreach ($schema->propertyNames() as $property) {
            $properties[$property] = $schema->requireProperty($property);
        }

        return $properties;
    }

    /**
     * The schema with other parts; what holds a value of its own (a $ref, a format, a default) stays.
     *
     * @param list<SchemaType> $types
     * @param array<int|string, Schema> $properties
     * @param list<string> $required
     * @param bool|Schema|null $additionalProperties
     * @param list<Schema> $allOf
     * @param list<Schema> $oneOf
     * @param list<Schema> $anyOf
     * @param array<int|string, JsonValue> $keywords
     */
    private function schema(Schema $schema, SchemaLocation $location, array $types, array $properties, array $required, ?Schema $items, $additionalProperties, array $allOf, array $oneOf, array $anyOf, array $keywords, Extensions $extensions): Schema
    {
        return new Schema(
            $location,
            $types,
            $schema->ref(),
            $schema->format(),
            $schema->description(),
            $schema->isDeprecated(),
            $schema->default(),
            $schema->enum(),
            $properties,
            $required,
            $items,
            $additionalProperties,
            $allOf,
            $oneOf,
            $anyOf,
            $schema->discriminator(),
            $keywords,
            $extensions,
        );
    }
}
