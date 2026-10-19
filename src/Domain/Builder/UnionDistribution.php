<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Model\Identifier;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Discriminator;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Extensions;
use MSSTC4PHP\DtoGenerator\Domain\Schema\ReferenceUse;
use MSSTC4PHP\DtoGenerator\Domain\Schema\ResolvedSchema;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaGraph;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaType;
use MSSTC4PHP\DtoGenerator\Domain\Schema\VariantOrigin;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;

/**
 * Writes an object whose `allOf` has a union of objects as the union of that object with each variant:
 * `allOf: [anyOf: [A, B], E]` is `anyOf: [allOf: [A, E], allOf: [B, E]]`, as for `oneOf`. Each of those is a class, which
 * no union of variants could be. A union with a discriminator, of anything but objects, beside another union, alone in
 * the `allOf` or with a variant that leads back to the schema stays as written, which the build reports.
 *
 * @phpstan-import-type JsonValue from Json
 */
final class UnionDistribution
{
    private const ONE_OF = 'oneOf';

    private const ANY_OF = 'anyOf';

    private SchemaGraph $graph;

    private Diagnostics $diagnostics;

    /** @var list<ResolvedSchema> the variants of named schemas, named themselves */
    private array $added = [];

    /** @var array<string, string> the references to them */
    private array $edges = [];

    /** @var array<string, VariantOrigin> where they come from, by location key */
    private array $origins = [];

    private function __construct(SchemaGraph $graph, Diagnostics $diagnostics)
    {
        $this->graph = $graph;
        $this->diagnostics = $diagnostics;
    }

    public static function of(SchemaGraph $graph, Diagnostics $diagnostics): SchemaGraph
    {
        // An allOf that names a distributed schema distributes in the next round. No variant leads back to the schema
        // or the union it is distributed over, so a round only reaches unions the variants reach without a cycle, and
        // the rounds end.
        do {
            $distribution = new self($graph, $diagnostics);
            $changed = [];
            foreach ($graph->all() as $resolved) {
                $schema = $distribution->rewrite($resolved->schema(), $resolved);
                if ($schema !== $resolved->schema()) {
                    $changed[] = new ResolvedSchema($schema, $resolved->source(), $resolved->name(), $resolved->isSelected());
                }
            }

            $graph = $graph->with(array_merge($changed, $distribution->added), $distribution->edges, $distribution->origins);
        } while ($changed !== []);

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
        // A lone union only annotates it, as a lone $ref does: there is nothing to add to each variant.
        if (!SchemaShape::mayBeObject($schema) || SchemaShape::hasUnion($schema) || $schema->discriminator() instanceof Discriminator
            || $schema->extensions()->has('x-php-skip')
            || ($schema->propertyNames() === [] && $schema->required() === [] && count($schema->allOf()) < 2)) {
            return null;
        }

        $unions = [];
        foreach ($schema->allOf() as $index => $member) {
            if (SchemaShape::hasUnion($this->end($member))) {
                $unions[$index] = $this->unionOf($member);
            }
        }

        $index = array_key_first($unions);
        $union = $index === null ? null : $unions[$index];
        if (count($unions) !== 1 || $union === null) {
            return null;
        }

        [$kind, $variants] = $union;
        $extensions = $schema->extensions()->all();
        unset($extensions['x-php-class-name']);
        $owner = $named instanceof ResolvedSchema ? $this->ownerName($named) : null;
        $names = $this->variantNames($variants);
        $members = [];
        foreach ($variants as $position => $variant) {
            $allOf = array_merge(array_slice($schema->allOf(), 0, $index), [$variant], array_slice($schema->allOf(), $index + 1));
            $keywords = $schema->keywords();
            unset($keywords['title']);
            $location = $schema->location()->child(VariantOrigin::SEGMENT, (string) $index, (string) $position);
            $member = $this->schema($schema, $location, $schema->nonNullTypes(), $this->properties($schema), $schema->required(), $schema->items(), $schema->additionalProperties(), $allOf, [], [], $keywords, new Extensions($extensions));
            $members[] = $named instanceof ResolvedSchema ? $this->named($member, $named, (string) $owner, $names[$position], [$kind, $position], $schema->allOf()[$index]->location()) : $member;
        }

        // What applies to the value as a whole (readOnly, a description) stays on the union.
        return $this->schema($schema, $schema->location(), $schema->types(), [], [], null, null, [], $kind === self::ONE_OF ? $members : [], $kind === self::ANY_OF ? $members : [], $schema->keywords(), new Extensions());
    }

    private function end(Schema $member): Schema
    {
        $chain = $this->graph->chain($member);

        return $chain[count($chain) - 1];
    }

    /**
     * The variants of a union of objects without a discriminator, written in the member or behind its `$ref`, none of
     * which leads back to the union, or to the schema, whose allOf leads to the union.
     *
     * @return array{'oneOf'|'anyOf', list<Schema>}|null
     */
    private function unionOf(Schema $member): ?array
    {
        $union = $this->end($member);
        if (!SchemaShape::mayBeObject($union) || $union->allOf() !== [] || $union->discriminator() instanceof Discriminator
            || $union->propertyNames() !== [] || $union->required() !== [] || $union->additionalProperties() !== null
            || ($union->oneOf() === []) === ($union->anyOf() === [])) {
            return null;
        }

        $kind = $union->oneOf() !== [] ? self::ONE_OF : self::ANY_OF;
        $variants = $kind === self::ONE_OF ? $union->oneOf() : $union->anyOf();
        foreach ($variants as $variant) {
            $end = $this->end($variant);
            $onlyNull = $end->types() !== [] && $end->nonNullTypes() === [];
            if ($onlyNull || !SchemaShape::mayBeObject($end) || SchemaShape::isEnum($end) || $this->leadsTo($variant, $union->location())) {
                return null;
            }
        }

        return [$kind, $variants];
    }

    /**
     * Whether a schema reaches the location through `$ref`s and the members of its compositions.
     */
    private function leadsTo(Schema $schema, SchemaLocation $location): bool
    {
        $seen = [];
        for ($pending = [$schema]; $pending !== [];) {
            $current = array_pop($pending);
            foreach ($this->graph->chain($current) as $link) {
                $key = $link->location()->toString();
                if ($key === $location->toString()) {
                    return true;
                }

                if (!isset($seen[$key])) {
                    $seen[$key] = $key;
                    array_push($pending, ...$link->allOf(), ...$link->oneOf(), ...$link->anyOf());
                }
            }
        }

        return false;
    }

    /**
     * The name of each variant: its schema name or title, `Option<N>` without one or when another variant has it.
     *
     * @param list<Schema> $variants
     *
     * @return list<string>
     */
    private function variantNames(array $variants): array
    {
        $names = array_map(fn (Schema $variant): ?string => $this->nameOf($variant), $variants);
        $counts = array_count_values(array_filter($names, 'is_string'));
        $unique = [];
        foreach ($names as $position => $name) {
            $unique[] = $name !== null && $counts[$name] === 1 ? $name : 'Option' . ($position + 1);
        }

        return $unique;
    }

    /**
     * The named schema's x-php-class-name, when it is a PHP identifier, else its name.
     */
    private function ownerName(ResolvedSchema $named): string
    {
        $extensions = $named->schema()->extensions();
        if (!$extensions->has('x-php-class-name')) {
            return $named->name();
        }

        $override = $extensions->get('x-php-class-name');
        if (is_string($override) && Identifier::isValid($override) && !Identifier::isReserved($override)) {
            return $override;
        }

        $this->diagnostics->error('"x-php-class-name" must be a PHP identifier that is not a reserved word.', $named->location()->child('x-php-class-name'));

        return $named->name();
    }

    /**
     * A variant of a named schema is a named schema of its own, `<Name> <Variant>`, so an allOf naming it extends it.
     *
     * @param string $base the owner's x-php-class-name or name
     * @param array{'oneOf'|'anyOf', int} $at where the union lists the variant
     */
    private function named(Schema $variant, ResolvedSchema $owner, string $base, string $variantName, array $at, SchemaLocation $union): Schema
    {
        [$kind, $position] = $at;
        $this->added[] = new ResolvedSchema($variant, $owner->source(), $base . ' ' . $variantName, $owner->isSelected());
        $origin = $this->graph->originOf($owner->location()) ?? new VariantOrigin($owner->location(), $base);
        $this->origins[$variant->location()->toString()] = $origin->withUnion($union);
        $ref = $variant->location()->toString();
        $at = $owner->location()->child($kind, (string) $position);
        $this->edges[(new ReferenceUse($ref, $at))->key()] = $ref;

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
