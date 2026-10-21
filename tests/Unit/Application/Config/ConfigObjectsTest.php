<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Application\Config;

use InvalidArgumentException;
use MSSTC4PHP\DtoGenerator\Application\Config\DtoSettings;
use MSSTC4PHP\DtoGenerator\Application\Config\ExtensionSettings;
use MSSTC4PHP\DtoGenerator\Application\Config\GeneratorConfig;
use MSSTC4PHP\DtoGenerator\Application\Config\NameOverride;
use MSSTC4PHP\DtoGenerator\Application\Config\OperationFilter;
use MSSTC4PHP\DtoGenerator\Application\Config\SourceConfig;
use MSSTC4PHP\DtoGenerator\Application\Config\TargetSettings;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
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

        self::assertTrue($settings->hasWithers());
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

    public function testASourceKeepsItsNamesInAnotherOutputDirectory(): void
    {
        $name = new NameOverride(NameOverride::CLASS_NAME, new SchemaLocation('/api.yaml', '/components/schemas/a'), 'A', new SchemaLocation('/c.yaml'));

        self::assertSame([$name], (new SourceConfig('/api.yaml', 'App', '/out', ['*'], [], [$name]))->withOutputDir('/other')->nameOverrides());
        self::assertSame([], (new SourceConfig('/api.yaml', 'App', '/out', ['*'], []))->nameOverrides());
        $filter = OperationFilter::all();
        self::assertSame($filter, (new SourceConfig('/api.yaml', 'App', '/out', ['*'], [], [], $filter))->withOutputDir('/other')->operations());
        self::assertNull((new SourceConfig('/api.yaml', 'App', '/out', ['*'], []))->operations());
    }

    public function testSelectsOperationsByIncludeAndExcludeGlobs(): void
    {
        $filter = new OperationFilter(['pets*', 'get /admin/*'], ['*Delete']);

        self::assertTrue($filter->selects('petsCreate'));
        self::assertTrue($filter->selects('get /admin/users'));
        self::assertFalse($filter->selects('petsDelete'));
        self::assertFalse($filter->selects('ordersCreate'));
        self::assertSame([['*'], []], [OperationFilter::all()->include(), OperationFilter::all()->exclude()]);
        $gapped = new OperationFilter([1 => 'a'], [2 => 'b']);
        self::assertSame([['a'], ['b']], [$gapped->include(), $gapped->exclude()]);
    }

    public function testAnOperationFilterNeedsAnIncludePattern(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('An operation filter must include at least one pattern.');

        new OperationFilter([], ['a']);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function malformedNameOverrides(): iterable
    {
        yield 'another keyword' => ['x-php-type', 'A', 'sets x-php-class-name or x-php-name, not "x-php-type"'];
        yield 'no name' => [NameOverride::PROPERTY_NAME, '', 'needs a name'];
    }

    /**
     * @dataProvider malformedNameOverrides
     */
    public function testANameOverrideSetsANameKeyword(string $keyword, string $name, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new NameOverride($keyword, new SchemaLocation('/api.yaml'), $name, new SchemaLocation('/c.yaml'));
    }
}
