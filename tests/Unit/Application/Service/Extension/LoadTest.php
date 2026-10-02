<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Application\Service\Extension;

use Closure;
use MSSTC4PHP\DtoGenerator\Application\Config\ExtensionSettings;
use MSSTC4PHP\DtoGenerator\Application\Config\GeneratorConfig;
use MSSTC4PHP\DtoGenerator\Application\Port\ExtensionFailed;
use MSSTC4PHP\DtoGenerator\Application\Port\ExtensionLoader;
use MSSTC4PHP\DtoGenerator\Application\Service\Extension\Load\Action;
use MSSTC4PHP\DtoGenerator\Application\Service\Extension\Load\Input;
use MSSTC4PHP\DtoGenerator\Application\Service\Extension\Load\Output;
use MSSTC4PHP\DtoGenerator\Contract\Extension;
use MSSTC4PHP\DtoGenerator\Contract\ExtensionRegistry;
use MSSTC4PHP\DtoGenerator\Contract\FormatMapping;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostic;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Model\ScalarType;
use MSSTC4PHP\DtoGenerator\Tests\Support\ConfigMother;
use MSSTC4PHP\DtoGenerator\Tests\Support\Extensions\MarkingExtension;
use MSSTC4PHP\DtoGenerator\Tests\Support\Extensions\MoneyFormatExtension;
use MSSTC4PHP\DtoGenerator\Tests\Support\Extensions\NamelessExtension;
use MSSTC4PHP\DtoGenerator\Tests\Support\FixedExtensionDiscovery;
use MSSTC4PHP\DtoGenerator\Tests\Support\GraphFixture;
use PHPUnit\Framework\TestCase;

final class LoadTest extends TestCase
{
    private const CONFIG = '/project/dto-generator.yaml#';

    public function testRegistersBuiltInExtensionsFirstThenTheConfiguredOnesInOrder(): void
    {
        $order = [];
        $loader = $this->loader([
            'App\First' => $this->extension('first', static function () use (&$order): void {
                $order[] = 'first';
            }),
            'App\Second' => $this->extension('second', static function (ExtensionRegistry $registry, array $config) use (&$order): void {
                $order[] = 'second ' . json_encode($config);
            }),
        ]);
        $builtIn = function (array $aliases) use (&$order): array {
            $order[] = 'built-in ' . implode(',', array_keys($aliases));

            return [$this->extension('custom', static function (): void {
            })];
        };

        $output = (new Action($loader, $builtIn, new FixedExtensionDiscovery()))(new Input(ConfigMother::configWithExtensions(
            new ExtensionSettings([ClassName::fromFqcn('App\First'), ClassName::fromFqcn('App\Second')], true, ['second' => ['level' => 2]], ['x-audit' => ['class' => 'App\Audited']], null),
            ConfigMother::source(GraphFixture::SPEC),
        )));

        self::assertSame(['built-in x-audit', 'first', 'second {"level":2}'], $order);
        self::assertSame([], $this->messages($output));
    }

    public function testAppendsDiscoveredExtensionsByPackageAfterTheConfiguredOnes(): void
    {
        $order = [];
        $loader = $this->loader([
            'App\First' => $this->recording('first', $order),
            'App\Alpha1' => $this->recording('alpha1', $order),
            'App\Alpha2' => $this->recording('alpha2', $order),
            'App\Zeta' => $this->recording('zeta', $order),
        ]);
        $discovery = new FixedExtensionDiscovery(['zeta/pkg' => ['App\Zeta'], 'alpha/pkg' => ['App\Alpha1', 'App\Alpha2'], 'beta/pkg' => ['app\FIRST']]);

        $output = (new Action($loader, static fn (): array => [], $discovery))(new Input($this->config([ClassName::fromFqcn('App\First')], true)));

        self::assertSame(['first', 'alpha1', 'alpha2', 'zeta'], $order);
        self::assertSame([], $this->messages($output));
    }

    public function testSortsDiscoveredExtensionsByPackageName(): void
    {
        $order = [];
        $names = ['d', 'c', 'b', 'a', 'e'];
        $extensions = [];
        $packages = [];
        foreach ($names as $name) {
            $extensions['App\\' . strtoupper($name)] = $this->recording($name, $order);
            $packages[$name . '/pkg'] = ['App\\' . strtoupper($name)];
        }

        (new Action($this->loader($extensions), static fn (): array => [], new FixedExtensionDiscovery($packages)))(new Input($this->config([], true)));

        self::assertSame(['a', 'b', 'c', 'd', 'e'], $order);
    }

    public function testSkipsADiscoveredExtensionThatIsBuiltIn(): void
    {
        $discovery = new FixedExtensionDiscovery(['acme/pkg' => [MarkingExtension::class]]);

        $output = (new Action($this->loader([MarkingExtension::class => new MarkingExtension()]), static fn (): array => [new MarkingExtension()], $discovery))(new Input($this->config([], true)));

        self::assertSame([], $this->messages($output));
    }

    public function testDiscoversNothingWhenTurnedOff(): void
    {
        $discovery = new FixedExtensionDiscovery(['zeta/pkg' => ['App\Zeta']], ['ignored']);

        $output = (new Action($this->loader([]), static fn (): array => [], $discovery))(new Input($this->config([], false)));

        self::assertSame(0, $discovery->calls);
        self::assertSame([], $this->messages($output));
    }

    public function testReportsTheProblemsOfDiscoveryAndExtensionsItCannotLoad(): void
    {
        $order = [];
        $discovery = new FixedExtensionDiscovery(['a/pkg' => ['App\Missing', 'App\Ok'], 'b/pkg' => ['App\Twin']], ['Package "c/pkg" declares 1 in extra.dto-generator.extensions, which is no class name.']);
        $loader = $this->loader(['App\Ok' => $this->recording('ok', $order), 'App\Twin' => $this->recording('ok', $order)]);

        $output = (new Action($loader, static fn (): array => [], $discovery))(new Input($this->config([], true)));

        self::assertSame(['ok'], $order);
        self::assertSame(
            [
                'warning ' . self::CONFIG . '/discoverExtensions: Package "c/pkg" declares 1 in extra.dto-generator.extensions, which is no class name.',
                'error ' . self::CONFIG . '/discoverExtensions: Extension App\Missing, discovered in package a/pkg, cannot be loaded: Class App\Missing does not exist.',
                'error ' . self::CONFIG . '/discoverExtensions: Extension App\Twin is named "ok" like an extension before it; it is not used.',
            ],
            $this->messages($output),
        );
    }

    public function testReportsAnExtensionThatCannotBeLoadedAndLoadsTheRest(): void
    {
        $output = (new Action($this->loader(['App\Ok' => new MarkingExtension()]), static fn (): array => [], new FixedExtensionDiscovery()))(new Input(ConfigMother::configWithExtensions(
            new ExtensionSettings([ClassName::fromFqcn('App\Missing'), ClassName::fromFqcn('App\Ok')], true, [], [], null),
            ConfigMother::source(GraphFixture::SPEC),
        )));

        self::assertSame(['error ' . self::CONFIG . '/extensions/0: Extension App\Missing cannot be loaded: Class App\Missing does not exist.'], $this->messages($output));
        self::assertTrue($output->registry()->isClaimed('x-marking-tag'));
    }

    public function testReportsAnAliasThatAnExtensionClaims(): void
    {
        $output = (new Action($this->loader(['App\Ok' => new MarkingExtension()]), static fn (): array => [], new FixedExtensionDiscovery()))(new Input(ConfigMother::configWithExtensions(
            new ExtensionSettings([ClassName::fromFqcn('App\Ok')], true, [], ['x-marking-color' => ['class' => 'App\Color'], 'x-audit' => ['class' => 'App\Audited']], null),
            ConfigMother::source(GraphFixture::SPEC),
        )));

        self::assertSame(['error ' . self::CONFIG . '/attributeAliases/x-marking-color: Alias "x-marking-color" is claimed by an extension.'], $this->messages($output));
    }

    public function testKeepsTheFormatsExtensionsRegister(): void
    {
        $loader = $this->loader(['App\Money' => $this->extension('money', static function (ExtensionRegistry $registry): void {
            $registry->addFormat('money', new FormatMapping(ScalarType::string('numeric-string')));
        })]);

        $output = (new Action($loader, static fn (): array => [], new FixedExtensionDiscovery()))(new Input(ConfigMother::configWithExtensions(
            new ExtensionSettings([ClassName::fromFqcn('App\Money')], true, [], [], null),
            ConfigMother::source(GraphFixture::SPEC),
        )));

        self::assertSame('numeric-string', $output->registry()->formats([])['money']->describe());
    }

    /**
     * @param list<ClassName> $classes
     */
    private function config(array $classes, bool $discover): GeneratorConfig
    {
        return ConfigMother::configWithExtensions(new ExtensionSettings($classes, $discover, [], [], null), ConfigMother::source(GraphFixture::SPEC));
    }

    /**
     * @param non-empty-string $name
     * @param list<string> $order
     */
    private function recording(string $name, array &$order): Extension
    {
        return $this->extension($name, static function () use ($name, &$order): void {
            $order[] = $name;
        });
    }

    /**
     * @param array<string, Extension> $extensions
     */
    private function loader(array $extensions): ExtensionLoader
    {
        return new class($extensions) implements ExtensionLoader {
            /** @var array<string, Extension> */
            private array $extensions;

            /**
             * @param array<string, Extension> $extensions
             */
            public function __construct(array $extensions)
            {
                $this->extensions = $extensions;
            }

            public function load(ClassName $class): Extension
            {
                $extension = $this->extensions[$class->fqcn()] ?? null;
                if (!$extension instanceof Extension) {
                    throw new ExtensionFailed(sprintf('Class %s does not exist.', $class->fqcn()));
                }

                return $extension;
            }
        };
    }

    /**
     * @param non-empty-string $name
     * @param Closure(ExtensionRegistry, array<int|string, mixed>):void $register
     */
    private function extension(string $name, Closure $register): Extension
    {
        return new class($name, $register) implements Extension {
            /** @var non-empty-string */
            private string $name;

            /** @var Closure(ExtensionRegistry, array<int|string, mixed>):void */
            private Closure $register;

            /**
             * @param non-empty-string $name
             * @param Closure(ExtensionRegistry, array<int|string, mixed>):void $register
             */
            public function __construct(string $name, Closure $register)
            {
                $this->name = $name;
                $this->register = $register;
            }

            public function name(): string
            {
                return $this->name;
            }

            public function register(ExtensionRegistry $registry, array $config): void
            {
                ($this->register)($registry, $config);
            }
        };
    }

    /**
     * @return list<string>
     */
    private function messages(Output $output): array
    {
        return array_map(static fn (Diagnostic $diagnostic): string => $diagnostic->toString(), $output->diagnostics()->all());
    }

    public function testReportsAnExtensionConfigThatIsNoObject(): void
    {
        $output = (new Action($this->loader(['App\Ok' => new MarkingExtension()]), static fn (): array => [], new FixedExtensionDiscovery()))(new Input(ConfigMother::configWithExtensions(
            new ExtensionSettings([ClassName::fromFqcn('App\Ok')], true, ['marking' => 'loud'], [], null),
            ConfigMother::source(GraphFixture::SPEC),
        )));

        self::assertSame(['error ' . self::CONFIG . '/extensionConfig/marking: The config of extension "marking" must be an object or a list.'], $this->messages($output));
    }

    public function testReportsTwoExtensionsWithOneNameAndConfigWithoutExtension(): void
    {
        $output = (new Action($this->loader(['App\A' => new MarkingExtension(), 'App\B' => new MarkingExtension(), 'App\C' => new MoneyFormatExtension()]), static fn (): array => [], new FixedExtensionDiscovery()))(new Input(ConfigMother::configWithExtensions(
            new ExtensionSettings([ClassName::fromFqcn('App\A'), ClassName::fromFqcn('App\B'), ClassName::fromFqcn('App\C')], true, ['typo-ext' => ['x' => 1], '7' => []], [], null),
            ConfigMother::source(GraphFixture::SPEC),
        )));

        self::assertSame(
            [
                'error ' . self::CONFIG . '/extensions/1: Extension App\\B is named "marking" like an extension before it; it is not used.',
                'warning ' . self::CONFIG . '/extensionConfig/typo-ext: No loaded extension is named "typo-ext", so this config is not used.',
                'warning ' . self::CONFIG . '/extensionConfig/7: No loaded extension is named "7", so this config is not used.',
            ],
            $this->messages($output),
        );
        self::assertSame(['money'], array_keys($output->registry()->formats([])));
    }

    public function testReportsAnExtensionThatCannotTellItsName(): void
    {
        $output = (new Action($this->loader(['App\\Nameless' => new NamelessExtension(), 'App\\Money' => new MoneyFormatExtension()]), static fn (): array => [], new FixedExtensionDiscovery()))(new Input(ConfigMother::configWithExtensions(
            new ExtensionSettings([ClassName::fromFqcn('App\\Nameless'), ClassName::fromFqcn('App\\Money')], true, [], [], null),
            ConfigMother::source(GraphFixture::SPEC),
        )));

        self::assertSame(['error ' . self::CONFIG . '/extensions/0: Extension App\\Nameless failed to give its name: no name yet'], $this->messages($output));
        self::assertSame(['money'], array_keys($output->registry()->formats([])));
    }
}
