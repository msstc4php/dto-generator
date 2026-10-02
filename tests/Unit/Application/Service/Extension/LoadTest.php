<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Application\Service\Extension;

use Closure;
use MSSTC4PHP\DtoGenerator\Application\Config\ExtensionSettings;
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

        $output = (new Action($loader, $builtIn))(new Input(ConfigMother::configWithExtensions(
            new ExtensionSettings([ClassName::fromFqcn('App\First'), ClassName::fromFqcn('App\Second')], true, ['second' => ['level' => 2]], ['x-audit' => ['class' => 'App\Audited']], null),
            ConfigMother::source(GraphFixture::SPEC),
        )));

        self::assertSame(['built-in x-audit', 'first', 'second {"level":2}'], $order);
        self::assertSame([], $this->messages($output));
    }

    public function testReportsAnExtensionThatCannotBeLoadedAndLoadsTheRest(): void
    {
        $output = (new Action($this->loader(['App\Ok' => new MarkingExtension()]), static fn (): array => []))(new Input(ConfigMother::configWithExtensions(
            new ExtensionSettings([ClassName::fromFqcn('App\Missing'), ClassName::fromFqcn('App\Ok')], true, [], [], null),
            ConfigMother::source(GraphFixture::SPEC),
        )));

        self::assertSame(['error ' . self::CONFIG . '/extensions/0: Extension App\Missing cannot be loaded: Class App\Missing does not exist.'], $this->messages($output));
        self::assertTrue($output->registry()->isClaimed('x-marking-tag'));
    }

    public function testReportsAnAliasThatAnExtensionClaims(): void
    {
        $output = (new Action($this->loader(['App\Ok' => new MarkingExtension()]), static fn (): array => []))(new Input(ConfigMother::configWithExtensions(
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

        $output = (new Action($loader, static fn (): array => []))(new Input(ConfigMother::configWithExtensions(
            new ExtensionSettings([ClassName::fromFqcn('App\Money')], true, [], [], null),
            ConfigMother::source(GraphFixture::SPEC),
        )));

        self::assertSame('numeric-string', $output->registry()->formats([])['money']->describe());
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
        $output = (new Action($this->loader(['App\Ok' => new MarkingExtension()]), static fn (): array => []))(new Input(ConfigMother::configWithExtensions(
            new ExtensionSettings([ClassName::fromFqcn('App\Ok')], true, ['marking' => 'loud'], [], null),
            ConfigMother::source(GraphFixture::SPEC),
        )));

        self::assertSame(['error ' . self::CONFIG . '/extensionConfig/marking: The config of extension "marking" must be an object or a list.'], $this->messages($output));
    }
}
