<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Service\Model\Enrich;

use MSSTC4PHP\DtoGenerator\Application\Service\Model\Build\BuiltClass;
use MSSTC4PHP\DtoGenerator\Contract\ClassContext;
use MSSTC4PHP\DtoGenerator\Contract\PropertyContext;
use MSSTC4PHP\DtoGenerator\Domain\Builder\AttributeCheck;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\PropertyModel;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaIndex;

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
            $model = $built->model();
            $schema = $index->get($model->source());
            if ($schema instanceof Schema) {
                $model = $this->enrich($model, $schema, $index, $input, $diagnostics);
            }

            AttributeCheck::checkImportAliases($model, $diagnostics);
            $classes[] = new BuiltClass($model, $built->source());
        }

        return new Output($classes, $diagnostics);
    }

    private function enrich(ClassModel $class, Schema $schema, SchemaIndex $index, Input $input, Diagnostics $diagnostics): ClassModel
    {
        $target = $input->target();
        $context = new ClassContext($class, $schema, $target, $input->packages(), $diagnostics);
        $attributes = AttributeCheck::admitted($input->registry()->enrichClass($context), $target, $schema->location(), $diagnostics);

        $properties = [];
        foreach ($class->properties() as $property) {
            $propertySchema = $index->get($property->source());
            $properties[] = $propertySchema instanceof Schema ? $this->enrichProperty($property, $class, $propertySchema, $input, $diagnostics) : $property;
        }

        return $class->withProperties(...$properties)->withAddedAttributes(...$attributes);
    }

    private function enrichProperty(PropertyModel $property, ClassModel $owner, Schema $schema, Input $input, Diagnostics $diagnostics): PropertyModel
    {
        $context = new PropertyContext($property, $owner, $schema, $input->target(), $input->packages(), $diagnostics);
        $attributes = $input->registry()->enrichProperty($context);

        return $property->withAddedAttributes(...AttributeCheck::admitted($attributes, $input->target(), $schema->location(), $diagnostics));
    }
}
