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
 * the schema name for every variant it leaves out.
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

            $candidates[] = [$target->name(), $class, $member->location(), false];
        }

        return $this->variants($schema, $discriminator, $candidates, true, $diagnostics);
    }

    /**
     * An inline object variant has the class the build declared for it and, as its name for the mapping, the one value
     * it gives the discriminator property.
     *
     * @param list<array{string, ClassName, SchemaLocation, bool}> $candidates
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
            $candidates[] = [$value, $class, $member->location(), true];
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
            $candidates[] = [$subclass->schema()->name(), $subclass->name(), $subclass->schema()->location(), false];
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
            $value = in_array($next->location()->toString(), $seen, true) ? null : $this->valueOf($next, $property, array_merge($seen, [$next->location()->toString()]));
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * The one value a declaration of the property allows, behind its `$ref` too.
     *
     * @param list<string> $seen
     */
    private function valueIn(Schema $declaration, array $seen): ?string
    {
        $target = $this->classes->target($declaration);
        if ($target instanceof ResolvedSchema) {
            $at = $target->schema()->location()->toString();

            return in_array($at, $seen, true) ? null : $this->valueIn($target->schema(), array_merge($seen, [$at]));
        }

        // A null next to the value only makes the property nullable, as in OpenAPI 3.0.
        $values = array_values(array_filter($declaration->hasKeyword('const') ? [$declaration->keyword('const')] : $declaration->enum() ?? [], static fn ($value): bool => $value !== null));

        return count($values) === 1 && (is_string($values[0]) || is_int($values[0])) ? (string) $values[0] : null;
    }

    /**
     * @param list<array{string, ClassName, SchemaLocation, bool}> $candidates the name each takes in the mapping by default,
     *                                                                         and whether the variant gives it inline
     */
    private function variants(Schema $schema, Discriminator $discriminator, array $candidates, bool $listed, Diagnostics $diagnostics): Variants
    {
        $classes = [];
        $names = [];
        $inline = [];
        foreach ($candidates as [$name, $class, $at, $given]) {
            if (isset($names[$class->fqcn()])) {
                $diagnostics->warning(sprintf('Variant %s is listed twice.', $class->fqcn()), $at);

                continue;
            }

            $classes[] = $class;
            $names[$class->fqcn()] = $name;
            if ($given) {
                $inline[$class->fqcn()] = $at;
            }
        }

        $mapping = [];
        $mapped = [];
        foreach ($discriminator->values() as $value) {
            $at = $schema->location()->child('discriminator', 'mapping', $value);
            $ref = $discriminator->refFor($value);
            // The loader resolved each mapping target from the mapping entry itself and reported any it could not.
            $target = $ref === null ? null : $this->graph->resolve(new ReferenceUse($ref, $at));
            if (!$target instanceof ResolvedSchema) {
                continue;
            }

            $class = $this->classes->classBehind($target);
            if (!$class instanceof ClassName || !isset($names[$class->fqcn()])) {
                $diagnostics->error(sprintf('Discriminator value "%s" maps to a schema that is not one of the variants.', $value), $at);

                continue;
            }

            $mapping[$value] = $class;
            $mapped[$class->fqcn()] = $value;
        }

        foreach ($classes as $class) {
            $name = $names[$class->fqcn()];
            if (isset($mapped[$class->fqcn()])) {
                continue;
            }

            // The value an inline variant gives is all that selects it.
            if (isset($mapping[$name]) && isset($inline[$class->fqcn()])) {
                $diagnostics->error(sprintf('Another variant already has discriminator value "%s", so %s can never be selected.', $name, $class->fqcn()), $inline[$class->fqcn()]);

                continue;
            }

            if (isset($mapping[$name])) {
                $diagnostics->warning(
                    sprintf('Variant %s gets no discriminator value: the mapping gives "%s" to another schema.', $class->fqcn(), $name),
                    $schema->location()->child('discriminator'),
                );

                continue;
            }

            $mapping[$name] = $class;
        }

        return new Variants($classes, $mapping === [] ? null : new DiscriminatorModel($discriminator->propertyName(), $mapping), $listed);
    }
}
