<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Service\Model\Build;

use Closure;
use MSSTC4PHP\DtoGenerator\Application\Config\ViewSuffixes;
use MSSTC4PHP\DtoGenerator\Domain\Builder\ClassBuilder;
use MSSTC4PHP\DtoGenerator\Domain\Builder\Direction;
use MSSTC4PHP\DtoGenerator\Domain\Builder\Directions;
use MSSTC4PHP\DtoGenerator\Domain\Builder\ViewDependence;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostic;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Model\DiscriminatorModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\PropertyModel;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaGraph;

/**
 * readWriteModels: split (spec F1 §4, §5): the classes that depend on the direction, read off a build that keeps every
 * property, then a build per direction, merged.
 */
final class Views
{
    private SchemaGraph $graph;

    private ViewSuffixes $suffixes;

    public function __construct(SchemaGraph $graph, ViewSuffixes $suffixes)
    {
        $this->graph = $graph;
        $this->suffixes = $suffixes;
    }

    /**
     * @param Closure(?View): Run $build one build of the graph, in a view or with every property
     */
    public function build(Closure $build): Output
    {
        $single = $build(null);
        // What the analysis reads of the flags is reported once whichever way the run goes: the views repeat it only
        // for the schemas that got a class.
        $found = new Diagnostics();
        $dependent = $this->dependent($single, new Directions($this->graph, $found));
        if ($dependent === []) {
            return $this->with($single->output(), $found);
        }

        $views = [];
        foreach ([Direction::READ, Direction::WRITE] as $value) {
            $direction = Direction::from($value);
            $views[] = $build(new View($direction, $this->suffixes->of($direction), $dependent))->output();
        }

        return $this->with($this->merge($views[0], $views[1]), $found);
    }

    /**
     * Both views of every dependent class, the classes and enums they share once, and each diagnostic once: one about a
     * dependent class names its read view only.
     */
    public function merge(Output $read, Output $write): Output
    {
        $diagnostics = new Diagnostics();
        $diagnostics->merge($read->diagnostics());

        $classes = $read->classes();
        $built = [];
        $readAt = [];
        foreach ($classes as $class) {
            $built[$class->model()->name()->fqcn()] = $class->model();
            $readAt[$class->model()->source()->toString()] = $class->model()->name()->fqcn();
        }

        $renamed = [];
        foreach ($write->classes() as $class) {
            $model = $class->model();
            $fqcn = $model->name()->fqcn();
            // Two schemas claiming one name are reported by the build that refused one of them.
            $earlier = $built[$fqcn] ?? null;
            if (!$earlier instanceof ClassModel) {
                $classes[] = $class;
                $renamed[$fqcn] = $readAt[$model->source()->toString()] ?? $fqcn;
            } elseif ($earlier->source()->equals($model->source()) && $this->shape($earlier) !== $this->shape($model)) {
                $diagnostics->error(
                    sprintf('%s comes out differently in the read and the write build, which is a defect of the generator; please report it.', $fqcn),
                    $model->source(),
                );
            }
        }

        $known = [];
        foreach ($read->diagnostics()->all() as $diagnostic) {
            $known[$diagnostic->toString()] = true;
        }

        foreach ($write->diagnostics()->all() as $diagnostic) {
            $translated = new Diagnostic($diagnostic->severity(), $this->renamed($diagnostic->message(), $renamed), $diagnostic->location());
            if (!($known[$translated->toString()] ?? false)) {
                $diagnostics->add($diagnostic);
            }
        }

        $enums = $read->enums();
        $declared = [];
        foreach ($enums as $enum) {
            $declared[$enum->model()->name()->fqcn()] = true;
        }

        foreach ($write->enums() as $enum) {
            if (!($declared[$enum->model()->name()->fqcn()] ?? false)) {
                $enums[] = $enum;
            }
        }

        return new Output($classes, $diagnostics, $enums);
    }

    /**
     * Locations of the object schemas whose classes depend on the direction: those with a directed property in any
     * member of their composition, those that hold, extend or list them, and a schema whose name another took when that
     * one depends on it, so the views refuse the name again.
     *
     * @return array<string, bool>
     */
    private function dependent(Run $single, Directions $directions): array
    {
        $dependent = [];
        $rejected = [];
        foreach ($single->classSchemas() as $classSchema) {
            $schema = $classSchema->schema();
            $additional = $schema->additionalProperties();
            $directed = $directions->ofSources(ClassBuilder::kept($classSchema->composition()->propertySources())) !== []
                || ($additional instanceof Schema && $directions->of($additional) instanceof Direction);
            $taker = $classSchema->taker();
            if ($taker !== null) {
                $rejected[$schema->location()->toString()] = $taker;
            } elseif ($directed) {
                $dependent[$schema->location()->toString()] = true;
            }
        }

        $models = [];
        $seeds = [];
        foreach ($single->output()->classes() as $class) {
            $model = $class->model();
            $models[] = $model;
            $seeds[$model->name()->fqcn()] = $dependent[$model->source()->toString()] ?? false;
        }

        $classes = ViewDependence::of($models, $seeds);
        foreach ($models as $model) {
            if ($classes[$model->name()->fqcn()] ?? false) {
                $dependent[$model->source()->toString()] = true;
            }
        }

        foreach ($rejected as $location => $taker) {
            if ($dependent[$taker] ?? false) {
                $dependent[$location] = true;
            }
        }

        return $dependent;
    }

    /**
     * The message with each write view's class named as its read view, whole names only (`PetWrite`, not inside
     * `PetWriteLog`).
     *
     * @param array<string, string> $renamed
     */
    private function renamed(string $message, array $renamed): string
    {
        return (string) preg_replace_callback(
            '~[A-Za-z_\\x80-\\xff\\\\][A-Za-z0-9_\\x80-\\xff\\\\]*~',
            static fn (array $name): string => $renamed[$name[0]] ?? $name[0],
            $message,
        );
    }

    private function with(Output $output, Diagnostics $more): Output
    {
        $diagnostics = new Diagnostics();
        $diagnostics->merge($output->diagnostics());
        $diagnostics->merge($more);

        return new Output($output->classes(), $diagnostics, $output->enums());
    }

    /**
     * What a shared class must be in both builds.
     *
     * @return array{string, ?string, list<array{string, string}>, array<array-key, string>}
     */
    private function shape(ClassModel $model): array
    {
        $parent = $model->parent();
        $discriminator = $model->discriminator();

        return [
            $model->kind()->value(),
            $parent instanceof ClassName ? $parent->fqcn() : null,
            array_map(static fn (PropertyModel $property): array => [$property->wireName(), $property->type()->describe()], $model->properties()),
            $discriminator instanceof DiscriminatorModel ? array_map(static fn (ClassName $variant): string => $variant->fqcn(), $discriminator->mapping()) : [],
        ];
    }
}
