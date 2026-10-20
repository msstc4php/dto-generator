<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Model\DiscriminatorModel;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Discriminator;
use MSSTC4PHP\DtoGenerator\Domain\Schema\ReferenceUse;
use MSSTC4PHP\DtoGenerator\Domain\Schema\ResolvedSchema;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaGraph;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;

/**
 * The variants of a discriminated schema (spec §5.3) and the discriminator value of each: the explicit mapping first,
 * for every variant it leaves out the one value its property allows, else the schema name.
 */
final class VariantResolver
{
    private SchemaGraph $graph;

    private ClassLookup $classes;

    public function __construct(SchemaGraph $graph, ClassLookup $classes)
    {
        $this->graph = $graph;
        $this->classes = $classes;
    }

    /**
     * The variants a `oneOf`/`anyOf` lists.
     */
    public function listed(Schema $schema, Discriminator $discriminator, Diagnostics $diagnostics): Variants
    {
        if ($schema->oneOf() !== [] && $schema->anyOf() !== []) {
            $diagnostics->warning(TypeMapper::ONE_OF_AND_ANY_OF, $schema->location());
        }

        $candidates = [];
        foreach (array_merge($schema->oneOf(), $schema->anyOf()) as $member) {
            if ($member->ref() === null) {
                $this->inline($member, $discriminator, $candidates, $diagnostics);

                continue;
            }

            // An unresolved $ref was already reported while loading.
            $target = $this->classes->target($member);
            if (!$target instanceof ResolvedSchema) {
                continue;
            }

            $class = $this->classes->classBehind($target);
            if (!$class instanceof ClassName) {
                $diagnostics->error('A variant of a discriminated union must be a $ref to an object schema.', $member->location());

                continue;
            }

            $candidates[] = [$target->name(), $class, $member->location(), $target->schema()];
        }

        return $this->variants($schema, $discriminator, $candidates, true, $diagnostics);
    }

    /**
     * An inline object variant has the class the build declared for it and, as its name for the mapping, the one value
     * it gives the discriminator property.
     *
     * @param list<array{string, ClassName, SchemaLocation, ?Schema}> $candidates
     */
    private function inline(Schema $member, Discriminator $discriminator, array &$candidates, Diagnostics $diagnostics): void
    {
        if (!SchemaShape::isClass($member)) {
            $diagnostics->error('A variant of a discriminated union must be a $ref to an object schema or an inline object.', $member->location());

            return;
        }

        $value = $this->valueOf($member, $discriminator->propertyName(), []);
        $class = $this->classes->classAt($member);
        if ($value === null) {
            $diagnostics->error(
                sprintf('An inline variant of a discriminated union needs one value of "%s", in "const" or a one-value "enum", as the mapping cannot name it.', $discriminator->propertyName()),
                $member->location(),
            );
        } elseif ($class instanceof ClassName) {
            $candidates[] = [$value, $class, $member->location(), null];
        }
    }

    /**
     * The classes that extend a discriminated schema through `allOf`, the way OpenAPI shows polymorphism.
     *
     * @param list<NamedClass> $subclasses
     */
    public function subclasses(Schema $schema, Discriminator $discriminator, array $subclasses, Diagnostics $diagnostics): Variants
    {
        $candidates = [];
        foreach ($subclasses as $subclass) {
            $candidates[] = [$subclass->schema()->name(), $subclass->name(), $subclass->schema()->location(), $subclass->schema()->schema()];
        }

        return $this->variants($schema, $discriminator, $candidates, false, $diagnostics);
    }

    /**
     * The one value a schema gives the property, in `const` or a one-value `enum`, here or in a member of its allOf.
     *
     * @param list<string> $seen locations on the way, against $ref cycles
     */
    private function valueOf(Schema $schema, string $property, array $seen): ?string
    {
        $declaration = $schema->property($property);
        $value = $declaration instanceof Schema ? $this->valueIn($declaration, $seen) : null;
        if ($value !== null) {
            return $value;
        }

        foreach ($schema->allOf() as $part) {
            $target = $this->classes->target($part);
            $next = $target instanceof ResolvedSchema ? $target->schema() : $part;
            $at = $next->location()->toString();
            if (in_array($at, $seen, true)) {
                continue;
            }

            $value = $this->valueOf($next, $property, array_merge($seen, [$at]));
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * The one value a declaration of the property allows: its own, behind its `$ref`, in a one-member `allOf` around it
     * (OpenAPI 3.0 describes a `$ref` so) or in every branch of a `oneOf`/`anyOf` but `null`.
     *
     * @param list<string> $seen
     */
    private function valueIn(Schema $declaration, array $seen): ?string
    {
        $own = $declaration->hasKeyword('const') ? [$declaration->keyword('const')] : $declaration->enum();
        if ($own !== null) {
            // A null next to the value only makes the property nullable, as in OpenAPI 3.0.
            $values = array_values(array_filter($own, static fn ($value): bool => $value !== null));

            return count($values) === 1 && (is_string($values[0]) || is_int($values[0])) ? (string) $values[0] : null;
        }

        $target = $this->classes->target($declaration);
        if ($target instanceof ResolvedSchema) {
            $at = $target->schema()->location()->toString();

            return in_array($at, $seen, true) ? null : $this->valueIn($target->schema(), array_merge($seen, [$at]));
        }

        $parts = $declaration->allOf();
        if (count($parts) === 1) {
            return $this->valueIn($parts[0], $seen);
        }

        $values = [];
        foreach (array_merge($declaration->oneOf(), $declaration->anyOf()) as $branch) {
            if (SchemaShape::isNull($branch)) {
                continue;
            }

            $value = $this->valueIn($branch, $seen);
            if ($value === null) {
                return null;
            }

            $values[$value] = $value;
        }

        return count($values) === 1 ? reset($values) : null;
    }

    /**
     * @param list<array{string, ClassName, SchemaLocation, ?Schema}> $candidates the schema name of each, or the value an
     *                                                                            inline one gives, and its schema (null
     *                                                                            when inline)
     */
    private function variants(Schema $schema, Discriminator $discriminator, array $candidates, bool $listed, Diagnostics $diagnostics): Variants
    {
        $classes = [];
        $names = [];
        $at = [];
        $schemas = [];
        foreach ($candidates as [$name, $class, $location, $variantSchema]) {
            if (isset($names[$class->fqcn()])) {
                $diagnostics->warning(sprintf('Variant %s is listed twice.', $class->fqcn()), $location);

                continue;
            }

            $classes[] = $class;
            $names[$class->fqcn()] = $name;
            $at[$class->fqcn()] = $location;
            if ($variantSchema instanceof Schema) {
                $schemas[$class->fqcn()] = $variantSchema;
            }
        }

        $mapping = $this->explicitMapping($schema, $discriminator, $names, $diagnostics);
        $explicit = $mapping;
        $mapped = [];
        foreach ($mapping as $value => $class) {
            $mapped[$class->fqcn()] = $value;
        }

        // The value an inline variant gives is all that selects it, so it goes before any schema name.
        $inline = [];
        $named = [];
        foreach ($classes as $class) {
            if (!isset($mapped[$class->fqcn()])) {
                if (isset($schemas[$class->fqcn()])) {
                    $named[] = $class;
                } else {
                    $inline[] = $class;
                }
            }
        }

        $unmapped = array_merge($inline, $named);
        $values = $this->implicitValues($unmapped, $names, $schemas, $explicit, $discriminator->propertyName());
        $winners = $this->winners($unmapped, $values, $schemas, $explicit, $at, $schema, $diagnostics);

        // Implicit values follow the variants' order, after the explicit mapping: it becomes the Serializer's map.
        foreach ($classes as $class) {
            if (isset($winners[$class->fqcn()])) {
                $mapping[$winners[$class->fqcn()]] = $class;
            }
        }

        return new Variants($classes, $mapping === [] ? null : new DiscriminatorModel($discriminator->propertyName(), $mapping), $listed);
    }

    /**
     * The value each variant the mapping leaves out keeps, first come first served, reporting the others.
     *
     * @param list<ClassName> $unmapped inline variants first
     * @param array<string, string> $values by FQCN
     * @param array<string, Schema> $schemas by FQCN, of the variants not inline
     * @param array<int|string, ClassName> $explicit the mapping
     * @param array<string, SchemaLocation> $at by FQCN
     *
     * @return array<string, string> by FQCN
     */
    private function winners(array $unmapped, array $values, array $schemas, array $explicit, array $at, Schema $schema, Diagnostics $diagnostics): array
    {
        $taken = $explicit;
        $winners = [];
        foreach ($unmapped as $class) {
            $value = $values[$class->fqcn()];
            if (isset($taken[$value]) && !isset($schemas[$class->fqcn()])) {
                $diagnostics->error(sprintf('Another variant already has discriminator value "%s", so %s can never be selected.', $value, $class->fqcn()), $at[$class->fqcn()]);

                continue;
            }

            if (isset($taken[$value])) {
                $diagnostics->warning(
                    sprintf(
                        isset($explicit[$value]) ? 'Variant %s gets no discriminator value: the mapping gives "%s" to another schema.' : 'Variant %s gets no discriminator value: another variant already has "%s".',
                        $class->fqcn(),
                        $value,
                    ),
                    $schema->location()->child('discriminator'),
                );

                continue;
            }

            $taken[$value] = $class;
            $winners[$class->fqcn()] = $value;
        }

        return $winners;
    }

    /**
     * @param array<string, string> $names the variants by FQCN
     *
     * @return array<int|string, ClassName>
     */
    private function explicitMapping(Schema $schema, Discriminator $discriminator, array $names, Diagnostics $diagnostics): array
    {
        $mapping = [];
        foreach ($discriminator->values() as $value) {
            $entry = $schema->location()->child('discriminator', 'mapping', $value);
            $ref = $discriminator->refFor($value);
            // The loader resolved each mapping target from the mapping entry itself and reported any it could not.
            $target = $ref === null ? null : $this->graph->resolve(new ReferenceUse($ref, $entry));
            if (!$target instanceof ResolvedSchema) {
                continue;
            }

            $class = $this->classes->classBehind($target);
            if (!$class instanceof ClassName || !isset($names[$class->fqcn()])) {
                $diagnostics->error(sprintf('Discriminator value "%s" maps to a schema that is not one of the variants.', $value), $entry);

                continue;
            }

            $mapping[$value] = $class;
        }

        return $mapping;
    }

    /**
     * The value each variant the mapping leaves out takes: the one value its property allows, as a property that allows
     * one never holds the schema name, when nothing else can claim it (the mapping, another variant's value or schema
     * name, or the value an inline variant gives); else its schema name.
     *
     * @param list<ClassName> $unmapped
     * @param array<string, string> $names by FQCN
     * @param array<string, Schema> $schemas by FQCN, of the variants not inline
     * @param array<int|string, ClassName> $explicit the mapping
     *
     * @return array<string, string> by FQCN
     */
    private function implicitValues(array $unmapped, array $names, array $schemas, array $explicit, string $property): array
    {
        // A value a subclass inherits from another variant is that variant's: the walk stops at every variant.
        $variants = [];
        foreach ($schemas as $schema) {
            $variants[] = $schema->location()->toString();
        }

        $taking = [];
        foreach ($unmapped as $class) {
            $fqcn = $class->fqcn();
            $value = isset($schemas[$fqcn]) ? $this->valueOf($schemas[$fqcn], $property, $variants) : null;
            if ($value !== null && $value !== $names[$fqcn] && !isset($explicit[$value])) {
                $taking[$fqcn] = $value;
            }
        }

        // A variant that gives up its value claims its schema name instead, which may take the value of another.
        do {
            $values = [];
            foreach ($unmapped as $class) {
                $values[$class->fqcn()] = $taking[$class->fqcn()] ?? $names[$class->fqcn()];
            }

            $counts = array_count_values($values);
            $before = count($taking);
            $taking = array_filter($taking, static fn (string $value): bool => $counts[$value] === 1);
        } while (count($taking) !== $before);

        return $values;
    }
}
