<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Support;

use MSSTC4PHP\DtoGenerator\Application\Config\DtoSettings;
use MSSTC4PHP\DtoGenerator\Application\Config\ExtensionSettings;
use MSSTC4PHP\DtoGenerator\Application\Config\GeneratorConfig;
use MSSTC4PHP\DtoGenerator\Application\Config\SourceConfig;
use MSSTC4PHP\DtoGenerator\Application\Config\TargetSettings;
use MSSTC4PHP\DtoGenerator\Domain\Target\AccessorStyle;
use MSSTC4PHP\DtoGenerator\Domain\Target\AllOfStrategy;
use MSSTC4PHP\DtoGenerator\Domain\Target\DateTimeClass;
use MSSTC4PHP\DtoGenerator\Domain\Target\Mutability;

final class ConfigMother
{
    public const PATH = '/project/dto-generator.yaml';

    public static function config(SourceConfig $source, SourceConfig ...$more): GeneratorConfig
    {
        return self::configWith(AllOfStrategy::from(AllOfStrategy::EXTENDS), $source, ...$more);
    }

    public static function configWith(AllOfStrategy $allOfStrategy, SourceConfig $source, SourceConfig ...$more): GeneratorConfig
    {
        return new GeneratorConfig(
            self::PATH,
            new TargetSettings(null, null, true),
            new DtoSettings(
                Mutability::from(Mutability::IMMUTABLE),
                AccessorStyle::from(AccessorStyle::AUTO),
                DateTimeClass::from(DateTimeClass::IMMUTABLE),
                $allOfStrategy,
            ),
            [],
            new ExtensionSettings([], true, [], [], null),
            array_merge([$source], $more),
        );
    }

    /**
     * @param list<string> $include
     * @param list<string> $exclude
     */
    public static function source(string $spec, array $include = ['*'], array $exclude = [], string $namespace = 'App\Dto'): SourceConfig
    {
        return new SourceConfig($spec, $namespace, '/project/src/Dto', $include, $exclude);
    }
}
