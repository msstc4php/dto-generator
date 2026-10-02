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
        $candidates = [];
        foreach (array_merge($schema->oneOf(), $schema->anyOf()) as $member) {
            if ($member->ref() === null) {
                $diagnostics->error('A variant of a discriminated union must be a $ref to an object schema.', $member->location());

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

            $candidates[] = [$target, $class, $member->location()];
        }

        return $this->variants($schema, $discriminator, $candidates, true, $diagnostics);
    }

    /**
     * The classes that extend a discriminated schema through `allOf`, the way OpenAPI shows polymorphism.
     *
     * @param list<array{ResolvedSchema, ClassName}> $subclasses
     */
    public function subclasses(Schema $schema, Discriminator $discriminator, array $subclasses, Diagnostics $diagnostics): Variants
    {
        $candidates = [];
        foreach ($subclasses as [$target, $class]) {
            $candidates[] = [$target, $class, $target->location()];
        }

        return $this->variants($schema, $discriminator, $candidates, false, $diagnostics);
    }

    /**
     * @param list<array{ResolvedSchema, ClassName, SchemaLocation}> $candidates
     */
    private function variants(Schema $schema, Discriminator $discriminator, array $candidates, bool $sharesProperties, Diagnostics $diagnostics): Variants
    {
        $classes = [];
        $names = [];
        foreach ($candidates as [$target, $class, $at]) {
            if (isset($names[$class->fqcn()])) {
                $diagnostics->warning(sprintf('Variant %s is listed twice.', $class->fqcn()), $at);

                continue;
            }

            $classes[] = $class;
            $names[$class->fqcn()] = $target->name();
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

            if (isset($mapping[$name])) {
                $diagnostics->warning(
                    sprintf('Variant %s gets no discriminator value: the mapping gives "%s" to another schema.', $class->fqcn(), $name),
                    $schema->location()->child('discriminator'),
                );

                continue;
            }

            $mapping[$name] = $class;
        }

        return new Variants($classes, $mapping === [] ? null : new DiscriminatorModel($discriminator->propertyName(), $mapping), $sharesProperties);
    }
}
