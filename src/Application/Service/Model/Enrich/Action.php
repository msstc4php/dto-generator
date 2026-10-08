<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Service\Model\Enrich;

use MSSTC4PHP\DtoGenerator\Application\Service\Model\Build\BuiltClass;
use MSSTC4PHP\DtoGenerator\Contract\ClassContext;
use MSSTC4PHP\DtoGenerator\Contract\PropertyContext;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassModel;
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
        $discriminators = [];
        foreach ($input->classes() as $built) {
            $discriminators[$built->model()->name()->fqcn()] = $built->model()->discriminator();
        }

        $classes = [];
        foreach ($input->classes() as $built) {
            $source = $built->model()->source();
            $inline = !$input->graph()->get($source) instanceof ResolvedSchema;
            $parent = $built->model()->parent();
            $parentDiscriminator = $parent === null ? null : $discriminators[$parent->fqcn()] ?? null;
            $model = $this->enrich($built->model(), $run->index()->require($source), $inline, $parentDiscriminator, $run);
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

    private function enrich(ClassModel $class, Schema $schema, bool $inline, ?DiscriminatorModel $parentDiscriminator, EnrichmentRun $run): ClassModel
    {
        $input = $run->input();
        $diagnostics = $run->diagnostics();
        $context = new ClassContext($class, $schema, $input->target(), $input->packages(), $diagnostics, $inline, $run->references(), $parentDiscriminator);
        $attributes = AttributeRules::admitted($input->registry()->enrichClass($context), $input->target(), $schema->location(), $diagnostics);
        $run->verify($attributes, $schema->location());

        $properties = [];
        foreach ($class->properties() as $property) {
            $properties[] = $this->enrichProperty($property, $class, $run->index()->require($property->source()), $parentDiscriminator, $run);
        }

        return $class->withProperties(...$properties)->withAddedAttributes(...$attributes);
    }

    private function enrichProperty(PropertyModel $property, ClassModel $owner, Schema $schema, ?DiscriminatorModel $parentDiscriminator, EnrichmentRun $run): PropertyModel
    {
        $input = $run->input();
        $diagnostics = $run->diagnostics();
        $context = new PropertyContext($property, $owner, $schema, $input->target(), $input->packages(), $diagnostics, $run->references(), $parentDiscriminator);
        $attributes = AttributeRules::admitted($input->registry()->enrichProperty($context), $input->target(), $schema->location(), $diagnostics);
        $run->verify($attributes, $schema->location());

        return $property->withAddedAttributes(...$attributes);
    }
}
