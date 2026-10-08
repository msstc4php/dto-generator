<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Service\Model\Enrich;

use MSSTC4PHP\DtoGenerator\Application\Service\Model\Build\BuiltClass;
use MSSTC4PHP\DtoGenerator\Contract\ClassContext;
use MSSTC4PHP\DtoGenerator\Contract\PropertyContext;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Model\DiscriminatorModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\PropertyModel;
use MSSTC4PHP\DtoGenerator\Domain\Schema\ResolvedSchema;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;
use MSSTC4PHP\DtoGenerator\Domain\Target\AttributeRules;
use MSSTC4PHP\DtoGenerator\Domain\Target\MetadataMode;

/**
 * Hands every class and each of its own properties to the enrichers (spec §8) and keeps the attributes the target
 * can render. Inherited properties are enriched where they are declared.
 */
final class Action
{
    public function __invoke(Input $input): Output
    {
        $run = new EnrichmentRun($input, new Diagnostics());
        $models = [];
        foreach ($input->classes() as $built) {
            $models[$built->model()->name()->fqcn()] = $built->model();
        }

        $classes = [];
        foreach ($input->classes() as $built) {
            $source = $built->model()->source();
            $inline = !$input->graph()->get($source) instanceof ResolvedSchema;
            $model = $this->enrich($built->model(), $run->index()->require($source), $inline, $this->selectingDiscriminator($built->model(), $models), $run);
            $this->checkRendering($model, $input->target()->metadata(), $run->diagnostics());
            $classes[] = new BuiltClass($model, $built->source());
        }

        return new Output($classes, $run->diagnostics());
    }

    /**
     * Rendered metadata, attributes or annotations, needs one import per alias.
     */
    private function checkRendering(ClassModel $class, MetadataMode $metadata, Diagnostics $diagnostics): void
    {
        if (!$metadata->isNone()) {
            AttributeRules::checkImportAliases($class, $diagnostics);
        }
    }

    /**
     * A deep hierarchy shares the discriminator of its base, which may select a grandchild directly.
     *
     * @param array<string, ClassModel> $models by FQCN
     */
    private function selectingDiscriminator(ClassModel $class, array $models): ?DiscriminatorModel
    {
        $ancestor = $class->parent();
        $seen = [];
        while ($ancestor !== null && isset($models[$ancestor->fqcn()]) && !isset($seen[$ancestor->fqcn()])) {
            $seen[$ancestor->fqcn()] = true;
            $model = $models[$ancestor->fqcn()];
            $discriminator = $model->discriminator();
            if ($discriminator !== null && in_array($class->name()->fqcn(), array_map(static fn (ClassName $name): string => $name->fqcn(), $discriminator->mapping()), true)) {
                return $discriminator;
            }

            $ancestor = $model->parent();
        }

        return null;
    }

    private function enrich(ClassModel $class, Schema $schema, bool $inline, ?DiscriminatorModel $selectingDiscriminator, EnrichmentRun $run): ClassModel
    {
        $input = $run->input();
        $diagnostics = $run->diagnostics();
        $context = new ClassContext($class, $schema, $input->target(), $input->packages(), $diagnostics, $inline, $run->references(), $selectingDiscriminator);
        $attributes = AttributeRules::admitted($input->registry()->enrichClass($context), $input->target(), $schema->location(), $diagnostics);
        $run->verify($attributes, $schema->location());

        $properties = [];
        foreach ($class->properties() as $property) {
            $properties[] = $this->enrichProperty($property, $class, $run->index()->require($property->source()), $run);
        }

        return $class->withProperties(...$properties)->withAddedAttributes(...$attributes);
    }

    private function enrichProperty(PropertyModel $property, ClassModel $owner, Schema $schema, EnrichmentRun $run): PropertyModel
    {
        $input = $run->input();
        $diagnostics = $run->diagnostics();
        $context = new PropertyContext($property, $owner, $schema, $input->target(), $input->packages(), $diagnostics, $run->references());
        $attributes = AttributeRules::admitted($input->registry()->enrichProperty($context), $input->target(), $schema->location(), $diagnostics);
        $run->verify($attributes, $schema->location());

        return $property->withAddedAttributes(...$attributes);
    }
}
