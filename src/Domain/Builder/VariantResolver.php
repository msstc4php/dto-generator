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

/**
 * The variants of a discriminated `oneOf`/`anyOf` (spec §5.3) and the discriminator value of each: the explicit
 * mapping first, the schema name for every variant it leaves out.
 */
final class VariantResolver
{
    private SchemaGraph $graph;

    private Declarations $declarations;

    public function __construct(SchemaGraph $graph, Declarations $declarations)
    {
        $this->graph = $graph;
        $this->declarations = $declarations;
    }

    public function resolve(Schema $schema, Discriminator $discriminator, Diagnostics $diagnostics): Variants
    {
        $classes = [];
        $byLocation = [];
        $names = [];
        foreach (array_merge($schema->oneOf(), $schema->anyOf()) as $member) {
            $ref = $member->ref();
            if ($ref === null) {
                $diagnostics->error('A variant of a discriminated union must be a $ref to an object schema.', $member->location());

                continue;
            }

            // An unresolved $ref was already reported while loading.
            $target = $this->graph->resolve(new ReferenceUse($ref, $member->location()));
            if (!$target instanceof ResolvedSchema) {
                continue;
            }

            $key = $target->location()->toString();
            $class = $this->declarations->classAt($key);
            if (!$class instanceof ClassName) {
                $diagnostics->error('A variant of a discriminated union must be a $ref to an object schema.', $member->location());

                continue;
            }

            $classes[] = $class;
            $byLocation[$key] = $class;
            $names[$key] = $target->name();
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

            $key = $target->location()->toString();
            if (!isset($byLocation[$key])) {
                $diagnostics->error(sprintf('Discriminator value "%s" maps to a schema that is not one of the variants.', $value), $at);

                continue;
            }

            $mapping[$value] = $byLocation[$key];
            $mapped[$key] = $value;
        }

        foreach ($byLocation as $key => $class) {
            if (!isset($mapped[$key]) && !isset($mapping[$names[$key]])) {
                $mapping[$names[$key]] = $class;
            }
        }

        return new Variants($classes, $mapping === [] ? null : new DiscriminatorModel($discriminator->propertyName(), $mapping));
    }
}
