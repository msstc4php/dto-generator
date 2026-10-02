<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Support;

use MSSTC4PHP\DtoGenerator\Application\Service\Model\Build\Action;
use MSSTC4PHP\DtoGenerator\Application\Service\Model\Build\BuiltClass;
use MSSTC4PHP\DtoGenerator\Application\Service\Model\Build\BuiltEnum;
use MSSTC4PHP\DtoGenerator\Application\Service\Model\Build\Input;
use MSSTC4PHP\DtoGenerator\Application\Service\Model\Build\Output;
use MSSTC4PHP\DtoGenerator\Domain\Builder\NameResolver;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostic;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Model\DiscriminatorModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\PropertyModel;
use MSSTC4PHP\DtoGenerator\Domain\Target\AccessorStyle;
use MSSTC4PHP\DtoGenerator\Domain\Target\AllOfStrategy;
use MSSTC4PHP\DtoGenerator\Domain\Target\DateTimeClass;
use MSSTC4PHP\DtoGenerator\Domain\Target\MetadataMode;
use MSSTC4PHP\DtoGenerator\Domain\Target\Mutability;
use MSSTC4PHP\DtoGenerator\Domain\Target\PhpVersion;
use MSSTC4PHP\DtoGenerator\Domain\Target\TargetProfile;

final class ModelFixture
{
    /**
     * @param array<int|string, array<array-key, mixed>> $schemas components/schemas of the spec
     * @param array<string, array<array-key, mixed>> $extraDocuments
     * @param list<string> $include
     */
    public static function build(array $schemas, array $extraDocuments = [], array $include = ['*'], string $allOfStrategy = AllOfStrategy::EXTENDS, bool $failOnLoadErrors = true): Output
    {
        $source = ConfigMother::source(GraphFixture::SPEC, $include);
        $graph = GraphFixture::load($schemas, $extraDocuments, $source, $failOnLoadErrors);
        $target = new TargetProfile(
            PhpVersion::fromString('8.2'),
            MetadataMode::from(MetadataMode::NONE),
            Mutability::from(Mutability::IMMUTABLE),
            AccessorStyle::from(AccessorStyle::AUTO),
            DateTimeClass::from(DateTimeClass::IMMUTABLE),
            true,
        );

        return (new Action(new NameResolver()))(new Input(ConfigMother::configWith(AllOfStrategy::from($allOfStrategy), $source), $target, $graph, []));
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
}
