<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Service\Model\Enrich;

use MSSTC4PHP\DtoGenerator\Application\Service\Model\Build\BuiltClass;
use MSSTC4PHP\DtoGenerator\Contract\ClassContext;
use MSSTC4PHP\DtoGenerator\Contract\PropertyContext;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\PropertyModel;
use MSSTC4PHP\DtoGenerator\Domain\Schema\ResolvedSchema;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaIndex;
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
        $diagnostics = new Diagnostics();
        $index = SchemaIndex::of($input->graph());
        $classes = [];
        foreach ($input->classes() as $built) {
            $source = $built->model()->source();
            $inline = !$input->graph()->get($source) instanceof ResolvedSchema;
            $model = $this->enrich($built->model(), $index->require($source), $inline, $index, $input, $diagnostics);
            $this->checkRendering($model, $input->target()->metadata(), $diagnostics);
            $classes[] = new BuiltClass($model, $built->source());
        }

        return new Output($classes, $diagnostics);
    }

    /**
     * Rendered metadata, attributes or annotations, needs one import per alias.
     */
    private function checkRendering(ClassModel $class, MetadataMode $metadata, Diagnostics $diagnostics): void
    {
        if ($metadata->value() !== MetadataMode::NONE) {
            AttributeRules::checkImportAliases($class, $diagnostics);
        }
    }

    private function enrich(ClassModel $class, Schema $schema, bool $inline, SchemaIndex $index, Input $input, Diagnostics $diagnostics): ClassModel
    {
        $target = $input->target();
        $context = new ClassContext($class, $schema, $target, $input->packages(), $diagnostics, $inline);
        $attributes = AttributeRules::admitted($input->registry()->enrichClass($context), $target, $schema->location(), $diagnostics);

        $properties = [];
        foreach ($class->properties() as $property) {
            $properties[] = $this->enrichProperty($property, $class, $index->require($property->source()), $input, $diagnostics);
        }

        return $class->withProperties(...$properties)->withAddedAttributes(...$attributes);
    }

    private function enrichProperty(PropertyModel $property, ClassModel $owner, Schema $schema, Input $input, Diagnostics $diagnostics): PropertyModel
    {
        $context = new PropertyContext($property, $owner, $schema, $input->target(), $input->packages(), $diagnostics);
        $attributes = $input->registry()->enrichProperty($context);

        return $property->withAddedAttributes(...AttributeRules::admitted($attributes, $input->target(), $schema->location(), $diagnostics));
    }
}
