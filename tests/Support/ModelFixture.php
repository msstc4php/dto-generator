<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Support;

use MSSTC4PHP\DtoGenerator\Application\Config\OperationFilter;
use MSSTC4PHP\DtoGenerator\Application\Config\SourceConfig;
use MSSTC4PHP\DtoGenerator\Application\Config\ViewSuffixes;
use MSSTC4PHP\DtoGenerator\Application\Service\Model\Build\Action;
use MSSTC4PHP\DtoGenerator\Application\Service\Model\Build\BuiltClass;
use MSSTC4PHP\DtoGenerator\Application\Service\Model\Build\BuiltEnum;
use MSSTC4PHP\DtoGenerator\Application\Service\Model\Build\Input;
use MSSTC4PHP\DtoGenerator\Application\Service\Model\Build\Output;
use MSSTC4PHP\DtoGenerator\Application\Service\Schemas\Load\Action as LoadAction;
use MSSTC4PHP\DtoGenerator\Application\Service\Schemas\Load\Input as LoadInput;
use MSSTC4PHP\DtoGenerator\Domain\Builder\Collections;
use MSSTC4PHP\DtoGenerator\Domain\Builder\NameResolver;
use MSSTC4PHP\DtoGenerator\Domain\Builder\SchemaParser;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostic;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Model\DiscriminatorModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\PropertyModel;
use MSSTC4PHP\DtoGenerator\Domain\Target\AccessorStyle;
use MSSTC4PHP\DtoGenerator\Domain\Target\AllOfStrategy;
use MSSTC4PHP\DtoGenerator\Domain\Target\DateTimeClass;
use MSSTC4PHP\DtoGenerator\Domain\Target\MetadataMode;
use MSSTC4PHP\DtoGenerator\Domain\Target\Mutability;
use MSSTC4PHP\DtoGenerator\Domain\Target\PhpVersion;
use MSSTC4PHP\DtoGenerator\Domain\Target\ReadWriteModels;
use MSSTC4PHP\DtoGenerator\Domain\Target\TargetProfile;

final class ModelFixture
{
    /**
     * @param array<int|string, array<array-key, mixed>> $schemas components/schemas of the spec
     * @param array<string, array<array-key, mixed>> $extraDocuments
     * @param list<string> $include
     * @param 'single'|'split' $readWriteModels the run's, when $split gives the suffixes
     */
    public static function build(array $schemas, array $extraDocuments = [], array $include = ['*'], string $allOfStrategy = AllOfStrategy::EXTENDS, bool $failOnLoadErrors = true, ?ViewSuffixes $split = null, string $readWriteModels = ReadWriteModels::SPLIT): Output
    {
        $source = ConfigMother::source(GraphFixture::SPEC, $include);
        $graph = GraphFixture::load($schemas, $extraDocuments, $source, $failOnLoadErrors);
        $config = $split instanceof ViewSuffixes ? ConfigMother::readWrite($source, $split, $readWriteModels, $allOfStrategy) : ConfigMother::configWith(AllOfStrategy::from($allOfStrategy), $source);

        return (new Action(new NameResolver()))(new Input($config, self::target(), $graph, []));
    }

    /**
     * A run whose lists collection classes may hold; with $split, one with read and write views.
     *
     * @param array<int|string, array<array-key, mixed>> $schemas components/schemas of the spec
     */
    public static function collections(array $schemas, ?Collections $collections, ?ViewSuffixes $split = null): Output
    {
        $source = ConfigMother::source(GraphFixture::SPEC);
        $config = ConfigMother::collections($source, $collections, $split);

        return (new Action(new NameResolver()))(new Input($config, self::target(), GraphFixture::load($schemas, [], $source), []));
    }

    /**
     * A run over a whole document whose every operation gives classes; load diagnostics come first.
     *
     * @param array<string, array<array-key, mixed>> $root the document without its version
     */
    public static function operations(array $root): Output
    {
        return self::document(['openapi' => '3.1.0'] + $root, OperationFilter::all());
    }

    /**
     * A run over a whole document as written, its version included; load diagnostics come first.
     *
     * @param array<string, mixed> $root
     * @param array<string, array<array-key, mixed>> $others other documents by path
     */
    public static function document(array $root, ?OperationFilter $operations = null, array $others = []): Output
    {
        $config = ConfigMother::config(new SourceConfig(GraphFixture::SPEC, 'App\Dto', '/project/src/Dto', ['*'], [], [], $operations));
        $loaded = (new LoadAction(new InMemoryDocumentLoader([GraphFixture::SPEC => $root] + $others), new SchemaParser()))(new LoadInput($config));
        $output = (new Action(new NameResolver()))(new Input($config, self::target(), $loaded->graph(), []));
        $diagnostics = new Diagnostics();
        $diagnostics->merge($loaded->diagnostics());
        $diagnostics->merge($output->diagnostics());

        return new Output($output->classes(), $diagnostics, $output->enums());
    }

    private static function target(): TargetProfile
    {
        return new TargetProfile(
            PhpVersion::fromString('8.2'),
            MetadataMode::from(MetadataMode::NONE),
            Mutability::from(Mutability::IMMUTABLE),
            AccessorStyle::from(AccessorStyle::AUTO),
            DateTimeClass::from(DateTimeClass::IMMUTABLE),
            true,
        );
    }

    /**
     * A run with read and write views, or a single run in which schemas may ask for them.
     *
     * @param array<int|string, array<array-key, mixed>> $schemas components/schemas of the spec
     * @param 'single'|'split' $readWriteModels
     */
    public static function views(array $schemas, ?ViewSuffixes $suffixes = null, string $readWriteModels = ReadWriteModels::SPLIT, string $allOfStrategy = AllOfStrategy::EXTENDS): Output
    {
        return self::build($schemas, [], ['*'], $allOfStrategy, true, $suffixes ?? new ViewSuffixes(), $readWriteModels);
    }

    /**
     * @return array<string, list<string>> class → "wire: type" per property
     */
    public static function classes(Output $output): array
    {
        $classes = [];
        foreach ($output->classes() as $class) {
            $classes[$class->model()->name()->fqcn()] = array_map(
                static fn (PropertyModel $property): string => $property->wireName() . ': ' . $property->type()->describe(),
                $class->model()->properties(),
            );
        }

        return $classes;
    }

    /**
     * @return array<string, list<string>> class → wire names of the inherited properties it requires, for those that do
     */
    public static function requiredInherited(Output $output): array
    {
        $classes = [];
        foreach ($output->classes() as $class) {
            if ($class->model()->requiredInherited() !== []) {
                $classes[$class->model()->name()->fqcn()] = $class->model()->requiredInherited();
            }
        }

        return $classes;
    }

    /**
     * @return list<string>
     */
    public static function messages(Output $output): array
    {
        return array_map(static fn (Diagnostic $d): string => $d->toString(), $output->diagnostics()->all());
    }

    /**
     * @return list<int>
     */
    public static function sources(Output $output): array
    {
        return array_map(static fn (BuiltClass $class): int => $class->source(), $output->classes());
    }

    /**
     * @return list<string> FQCNs of the built enums
     */
    public static function enums(Output $output): array
    {
        return array_map(static fn (BuiltEnum $enum): string => $enum->model()->name()->fqcn(), $output->enums());
    }

    /**
     * @return array<string, string> class → "kind[ extends Parent][ by property {value: Class, …}]"
     */
    public static function hierarchy(Output $output): array
    {
        $hierarchy = [];
        foreach ($output->classes() as $class) {
            $model = $class->model();
            $line = $model->kind()->value();
            $parent = $model->parent();
            if ($parent instanceof ClassName) {
                $line .= ' extends ' . $parent->fqcn();
            }

            if ($model->interfaces() !== []) {
                $line .= ' implements ' . implode(', ', array_map(static fn (ClassName $name): string => $name->fqcn(), $model->interfaces()));
            }

            $discriminator = $model->discriminator();
            if ($discriminator instanceof DiscriminatorModel) {
                $mapping = [];
                foreach ($discriminator->values() as $value) {
                    $target = $discriminator->classFor($value);
                    $mapping[] = $value . ': ' . ($target instanceof ClassName ? $target->fqcn() : '?');
                }

                $line .= ' by ' . $discriminator->propertyName() . ' {' . implode(', ', $mapping) . '}';
            }

            $hierarchy[$model->name()->fqcn()] = $line;
        }

        return $hierarchy;
    }

    /**
     * @return array<string, list<string>> concrete class → "property: own|own[ unchecked][; subclasses: value|value[ unchecked]]"
     *                                     per discriminator, root first
     */
    public static function selections(Output $output): array
    {
        $selections = [];
        foreach ($output->classes() as $class) {
            $lines = [];
            foreach ($class->model()->discriminatorValues() as $values) {
                $literals = static fn (array $list): string => implode('|', array_map(static fn ($value): string => var_export($value, true), $list));
                $line = $values->property() . ': ' . $literals($values->ownValues()) . ($values->isChecked() ? '' : ' unchecked');
                if ($values->subclassValues() !== []) {
                    $line .= '; subclasses: ' . $literals($values->subclassValues()) . ($values->areSubclassValuesChecked() ? '' : ' unchecked');
                }

                $lines[] = $line;
            }

            if ($lines !== []) {
                $selections[$class->model()->name()->fqcn()] = $lines;
            }
        }

        return $selections;
    }
}
