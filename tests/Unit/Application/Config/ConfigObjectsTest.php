<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Application\Config;

use InvalidArgumentException;
use MSSTC4PHP\DtoGenerator\Application\Config\DtoSettings;
use MSSTC4PHP\DtoGenerator\Application\Config\ExtensionSettings;
use MSSTC4PHP\DtoGenerator\Application\Config\GeneratorConfig;
use MSSTC4PHP\DtoGenerator\Application\Config\SourceConfig;
use MSSTC4PHP\DtoGenerator\Application\Config\TargetSettings;
use MSSTC4PHP\DtoGenerator\Domain\Target\AccessorStyle;
use MSSTC4PHP\DtoGenerator\Domain\Target\AllOfStrategy;
use MSSTC4PHP\DtoGenerator\Domain\Target\DateTimeClass;
use MSSTC4PHP\DtoGenerator\Domain\Target\Mutability;
use MSSTC4PHP\DtoGenerator\Tests\Support\ConfigMother;
use PHPUnit\Framework\TestCase;

final class ConfigObjectsTest extends TestCase
{
    /**
     * @dataProvider relativeSourcePaths
     */
    public function testSourcePathsMustBeAbsolute(string $spec, string $outputDir): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Source paths must be absolute');

        new SourceConfig($spec, 'App', $outputDir, ['*'], []);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function relativeSourcePaths(): array
    {
        return [
            'relative spec' => ['api.yaml', '/out'],
            'relative output' => ['/api.yaml', 'out'],
        ];
    }

    public function testDtoSettingsKeepWithersByDefault(): void
    {
        $settings = new DtoSettings(
            Mutability::from(Mutability::IMMUTABLE),
            AccessorStyle::from(AccessorStyle::AUTO),
            DateTimeClass::from(DateTimeClass::IMMUTABLE),
            AllOfStrategy::from(AllOfStrategy::EXTENDS),
        );

        self::assertTrue($settings->withers());
    }

    public function testASourceNeedsAnIncludePattern(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at least one pattern');

        new SourceConfig('/api.yaml', 'App', '/out', [], []);
    }

    public function testTheConfigPathMustBeAbsolute(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Config path "dto-generator.yaml" must be absolute');

        new GeneratorConfig(
            'dto-generator.yaml',
            new TargetSettings(null, null, true),
            new DtoSettings(
                Mutability::from(Mutability::IMMUTABLE),
                AccessorStyle::from(AccessorStyle::AUTO),
                DateTimeClass::from(DateTimeClass::IMMUTABLE),
                AllOfStrategy::from(AllOfStrategy::EXTENDS),
            ),
            [],
            new ExtensionSettings([], true, [], [], null),
            [ConfigMother::source('/api.yaml')],
        );
    }

    public function testSelectsComponentNamesByIncludeAndExcludeGlobs(): void
    {
        $source = new SourceConfig('/api.yaml', 'App', '/out', ['User*', 'Tag'], ['*Internal']);

        self::assertTrue($source->selects('UserProfile'));
        self::assertTrue($source->selects('Tag'));
        self::assertFalse($source->selects('UserInternal'));
        self::assertFalse($source->selects('Order'));
    }
}
