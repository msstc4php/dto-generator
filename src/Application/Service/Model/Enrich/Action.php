<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Service\Model\Enrich;

use MSSTC4PHP\DtoGenerator\Application\Port\ClassVerifier;
use MSSTC4PHP\DtoGenerator\Application\Service\Model\Build\BuiltClass;
use MSSTC4PHP\DtoGenerator\Contract\ClassContext;
use MSSTC4PHP\DtoGenerator\Contract\PropertyContext;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Model\ArgumentValue;
use MSSTC4PHP\DtoGenerator\Domain\Model\AttributeModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
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

        $this->verify($attributes, $input, $schema, $diagnostics);

        return $class->withProperties(...$properties)->withAddedAttributes(...$attributes);
    }

    private function enrichProperty(PropertyModel $property, ClassModel $owner, Schema $schema, Input $input, Diagnostics $diagnostics): PropertyModel
    {
        $context = new PropertyContext($property, $owner, $schema, $input->target(), $input->packages(), $diagnostics);
        $attributes = AttributeRules::admitted($input->registry()->enrichProperty($context), $input->target(), $schema->location(), $diagnostics);
        $this->verify($attributes, $input, $schema, $diagnostics);

        return $property->withAddedAttributes(...$attributes);
    }

    /**
     * With verifyClasses on, every class and constant a rendered attribute names must exist for the consumer.
     *
     * @param list<AttributeModel> $attributes
     */
    private function verify(array $attributes, Input $input, Schema $schema, Diagnostics $diagnostics): void
    {
        $verifier = $input->verifier();
        if (!$verifier instanceof ClassVerifier || $input->target()->metadata()->value() === MetadataMode::NONE) {
            return;
        }

        foreach ($attributes as $attribute) {
            $name = $attribute->className();
            foreach ($attribute->arguments() as $argument) {
                foreach ($this->missing($argument->value(), $verifier) as $missing) {
                    $diagnostics->error(sprintf('%s, used by attribute %s, does not exist.', $missing, $name->fqcn()), $schema->location());
                }
            }

            if (!$verifier->hasClass($name)) {
                $diagnostics->error(sprintf('Attribute class %s does not exist.', $name->fqcn()), $schema->location());
            }
        }
    }

    /**
     * @return list<string> what the value names and the consumer lacks, like "Class App\\X" or "Constant App\\X::Y"
     */
    private function missing(ArgumentValue $value, ClassVerifier $verifier): array
    {
        switch ($value->kind()) {
            case ArgumentValue::KIND_LIST:
                $items = $value->listItems();

                break;
            case ArgumentValue::KIND_MAP:
                $items = $value->mapItems();

                break;
            case ArgumentValue::KIND_CONSTANT:
                $class = $value->constantClass();
                $name = ($class instanceof ClassName ? $class->fqcn() . '::' : '') . $value->constantName();

                return $verifier->hasConstant($class, $value->constantName()) ? [] : ['Constant ' . $name];
            case ArgumentValue::KIND_CLASS_REFERENCE:
                return $verifier->hasClass($value->className()) ? [] : ['Class ' . $value->className()->fqcn()];
            case ArgumentValue::KIND_NEW_INSTANCE:
                $missing = $verifier->hasClass($value->className()) ? [] : ['Class ' . $value->className()->fqcn()];
                foreach ($value->arguments() as $argument) {
                    $missing = array_merge($missing, $this->missing($argument->value(), $verifier));
                }

                return $missing;
            default:
                return [];
        }

        $missing = [];
        foreach ($items as $item) {
            $missing = array_merge($missing, $this->missing($item, $verifier));
        }

        return $missing;
    }
}
