<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Service\Model\Build;

use Closure;
use MSSTC4PHP\DtoGenerator\Application\Config\ViewSuffixes;
use MSSTC4PHP\DtoGenerator\Domain\Builder\ClassBuilder;
use MSSTC4PHP\DtoGenerator\Domain\Builder\Direction;
use MSSTC4PHP\DtoGenerator\Domain\Builder\Directions;
use MSSTC4PHP\DtoGenerator\Domain\Builder\ReadWriteMode;
use MSSTC4PHP\DtoGenerator\Domain\Builder\ViewDependence;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostic;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassKind;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Model\DiscriminatorModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\PropertyModel;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaGraph;
use MSSTC4PHP\DtoGenerator\Domain\Target\ReadWriteModels;

/**
 * Read and write views (spec F1 §4, §5, F4): the classes that depend on the direction, read off a build that keeps every
 * property, then a build per direction, merged; without such classes, the build that keeps every property.
 */
final class Views
{
    private SchemaGraph $graph;

    private ViewSuffixes $suffixes;

    private ReadWriteMode $mode;

    public function __construct(SchemaGraph $graph, ViewSuffixes $suffixes, ReadWriteMode $mode)
    {
        $this->graph = $graph;
        $this->suffixes = $suffixes;
        $this->mode = $mode;
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
        $this->warnAboutOverriddenSingles($single, $dependent, $found);
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
        $opened = [];
        foreach ($write->classes() as $class) {
            $model = $class->model();
            $fqcn = $model->name()->fqcn();
            // Two schemas claiming one name are reported by the build that refused one of them.
            $earlier = $built[$fqcn] ?? null;
            if (!$earlier instanceof ClassModel) {
                $classes[] = $class;
                $renamed[$fqcn] = $readAt[$model->source()->toString()] ?? $fqcn;
            } elseif ($earlier->source()->equals($model->source())) {
                $open = $this->reconcile($earlier, $class, $diagnostics);
                if ($open instanceof BuiltClass) {
                    $opened[$fqcn] = $open;
                }
            }
        }

        // A class that a class of one view extends is open in that view only; the class both share stays open.
        $classes = array_map(static fn (BuiltClass $class): BuiltClass => $opened[$class->model()->name()->fqcn()] ?? $class, $classes);

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
     * Locations of the object schemas whose classes depend on the direction: split ones with a directed property in any
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
            // The flags of a class kept as one model are not read, so they are not reported either.
            $directed = $this->mode->splits($schema) && (
                $directions->ofSources(ClassBuilder::kept($classSchema->composition()->propertySources())) !== []
                || ($additional instanceof Schema && $directions->of($additional) instanceof Direction)
            );
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
     * A class that asked for one model gets views all the same when it holds, extends or lists a class with views; a
     * schema refused its name has no class to warn about.
     *
     * @param array<string, bool> $dependent
     */
    private function warnAboutOverriddenSingles(Run $single, array $dependent, Diagnostics $diagnostics): void
    {
        foreach ($single->classSchemas() as $classSchema) {
            $schema = $classSchema->schema();
            if ($classSchema->taker() !== null || !($dependent[$schema->location()->toString()] ?? false)) {
                continue;
            }

            $declared = ReadWriteMode::declared($schema);
            if ($declared instanceof ReadWriteModels && !$declared->isSplit()) {
                $diagnostics->warning(
                    sprintf('"%s: single" is overridden: the class holds, extends or lists a class with read and write views, so it gets them too.', ReadWriteMode::KEY),
                    $schema->location()->child(ReadWriteMode::KEY),
                );
            }
        }
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
     * A shared class the two builds agree on but that one extends: open in that build and final in the other, the class
     * both share is the open one, which the write build gives when it is that build. Anything else is a defect.
     */
    private function reconcile(ClassModel $read, BuiltClass $write, Diagnostics $diagnostics): ?BuiltClass
    {
        $readShape = $this->shape($read);
        $writeShape = $this->shape($write->model());
        if ($readShape === $writeShape) {
            return null;
        }

        $kinds = [$readShape[0], $writeShape[0]];
        if (array_slice($readShape, 1) === array_slice($writeShape, 1)
            && [$read->discriminatedProperties(), $read->restoredMutators()] === [$write->model()->discriminatedProperties(), $write->model()->restoredMutators()]
            // Value objects: equal when their properties are.
            && serialize([$read->discriminatorValues(), $read->attributes()]) === serialize([$write->model()->discriminatorValues(), $write->model()->attributes()])
            && in_array($kinds, [[ClassKind::FINAL, ClassKind::OPEN], [ClassKind::OPEN, ClassKind::FINAL]], true)) {
            return $kinds[1] === ClassKind::OPEN ? $write : null;
        }

        $diagnostics->error(
            sprintf('%s comes out differently in the read and the write build, which is a defect of the generator; please report it.', $read->name()->fqcn()),
            $read->source(),
        );

        return null;
    }

    /**
     * What a shared class must be in both builds.
     *
     * @return array{string, ?string, list<array{string, string}>, array<array-key, string>, list<string>}
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
            array_map(static fn (ClassName $interface): string => $interface->fqcn(), $model->interfaces()),
        ];
    }
}
