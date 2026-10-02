<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Functional;

use Closure;
use Composer\Composer;
use Composer\EventDispatcher\EventDispatcher;
use Composer\Factory;
use Composer\IO\BufferIO;
use Composer\Package\RootPackage;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use FilesystemIterator;
use LogicException;
use MSSTC4PHP\DtoGenerator\Application\Service\Generate\Input;
use MSSTC4PHP\DtoGenerator\Application\Service\Generate\Output;
use MSSTC4PHP\DtoGenerator\ComposerPlugin;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;

final class ComposerPluginTest extends TestCase
{
    private const CONFIG = "version: 1\ntarget: {php: '8.2'}\nverifyClasses: false\ndiscoverExtensions: false\nsources:\n  - {spec: api.yaml, namespace: App\\Dto, outputDir: out}\n";

    private const SPEC = "openapi: 3.1.0\ncomponents:\n  schemas:\n    Pet:\n      type: object\n      properties:\n        name: {type: string}\n";

    private string $root;

    private string $cwd;

    protected function setUp(): void
    {
        $this->cwd = (string) getcwd();
        $this->root = sys_get_temp_dir() . '/dto-generator-plugin-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/gen', 0777, true);
        file_put_contents($this->root . '/gen/dto-generator.yaml', self::CONFIG);
        file_put_contents($this->root . '/gen/api.yaml', self::SPEC);
        chdir($this->root);
    }

    protected function tearDown(): void
    {
        chdir($this->cwd);
        $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) {
            assert($entry instanceof SplFileInfo);
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }

        rmdir($this->root);
    }

    public function testListensToTheDumpOfTheAutoloader(): void
    {
        self::assertSame([ScriptEvents::POST_AUTOLOAD_DUMP => 'onPostAutoloadDump'], ComposerPlugin::getSubscribedEvents());
    }

    public function testDoesNothingWithoutAConfig(): void
    {
        self::assertSame('', $this->dump([]));
        self::assertSame('', $this->dump(['dto-generator' => ['failOnError' => true]]));
        self::assertDirectoryDoesNotExist($this->root . '/gen/out');
    }

    public function testDoesNothingWhenComposerRunsWithoutScripts(): void
    {
        self::assertSame('', $this->dump(['dto-generator' => ['config' => 'gen/dto-generator.yaml']], null, false));
        self::assertDirectoryDoesNotExist($this->root . '/gen/out');
    }

    public function testGeneratesFromAConfigRelativeToTheProject(): void
    {
        $output = $this->dump(['dto-generator' => ['config' => 'gen/dto-generator.yaml']]);

        self::assertSame("dto-generator: written 1, deleted 0, unchanged 0.\n", $output);
        self::assertFileExists($this->root . '/gen/out/Pet.php');
        self::assertSame("dto-generator: written 0, deleted 0, unchanged 1.\n", $this->dump(['dto-generator' => ['config' => 'gen/dto-generator.yaml']]));
    }

    public function testCountsUpdatedFilesAsWritten(): void
    {
        $this->dump(['dto-generator' => ['config' => 'gen/dto-generator.yaml']]);
        file_put_contents($this->root . '/gen/api.yaml', self::SPEC . "        age: {type: integer}\n");

        self::assertSame("dto-generator: written 1, deleted 0, unchanged 0.\n", $this->dump(['dto-generator' => ['config' => 'gen/dto-generator.yaml']]));
    }

    public function testAcceptsAnAbsoluteConfigPath(): void
    {
        $this->dump(['dto-generator' => ['config' => $this->root . '/gen/dto-generator.yaml']]);

        self::assertFileExists($this->root . '/gen/out/Pet.php');
    }

    public function testWarnsAboutFailuresUnlessToldToFail(): void
    {
        file_put_contents($this->root . '/gen/api.yaml', "openapi: 3.1.0\ncomponents:\n  schemas:\n    Pet: {type: object, properties: {owner: {\$ref: '#/components/schemas/Gone'}}}\n");

        $output = $this->dump(['dto-generator' => ['config' => 'gen/dto-generator.yaml']]);

        self::assertStringContainsString('dto-generator: error gen/api.yaml#/components/schemas/Pet/properties/owner: $ref "#/components/schemas/Gone" does not resolve', $output);
        self::assertStringEndsWith("dto-generator: generation failed with 1 error(s); nothing was written.\n", $output);
        self::assertDirectoryDoesNotExist($this->root . '/gen/out');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('dto-generator: generation failed with 1 error(s); nothing was written.');
        $this->dump(['dto-generator' => ['config' => 'gen/dto-generator.yaml', 'failOnError' => true]]);
    }

    public function testReportsAGeneratorThatStops(): void
    {
        $stopping = static function (): Output {
            throw new LogicException('out of memory');
        };

        self::assertSame("dto-generator: generation stopped: out of memory\n", $this->dump(['dto-generator' => ['config' => 'gen/dto-generator.yaml']], $stopping));
    }

    public function testReportsAMissingConfigFile(): void
    {
        $output = $this->dump(['dto-generator' => ['config' => 'missing.yaml']]);

        self::assertStringEndsWith("dto-generator: configuration failed with 1 error(s); nothing was written.\n", $output);
    }

    public function testKeepsAngleBracketsOfMessagesAsText(): void
    {
        self::assertStringContainsString('missing<b>.yaml" does not exist.', $this->dump(['dto-generator' => ['config' => 'missing<b>.yaml']]));
    }

    public function testShowsErrorsAsErrorsWhenTheyFailTheCommand(): void
    {
        $io = $this->io(true);
        try {
            $this->runPlugin(['dto-generator' => ['config' => 'missing.yaml', 'failOnError' => true]], $io);
            self::fail('The failure did not fail the command.');
        } catch (RuntimeException $exception) {
            self::assertStringStartsWith("\033[37;41mdto-generator: error missing.yaml: ", $io->getOutput());
        }

        $io = $this->io(true);
        $this->runPlugin(['dto-generator' => ['config' => 'missing.yaml']], $io);
        self::assertStringStartsWith("\033[30;43mdto-generator: error missing.yaml: ", $io->getOutput());
    }

    public function testReportsSettingsOfTheWrongType(): void
    {
        self::assertSame(
            "dto-generator: extra.dto-generator must be an object with \"config\", the path of the config file.\n",
            $this->dump(['dto-generator' => 'gen/dto-generator.yaml']),
        );
        self::assertSame(
            "dto-generator: extra.dto-generator.config must be the path of the config file.\n",
            $this->dump(['dto-generator' => ['config' => '']]),
        );
        self::assertSame(
            "dto-generator: extra.dto-generator.failOnError must be true or false.\n",
            $this->dump(['dto-generator' => ['config' => 'gen/dto-generator.yaml', 'failOnError' => 'yes']]),
        );
        self::assertDirectoryDoesNotExist($this->root . '/gen/out');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('dto-generator: extra.dto-generator.config must be the path of the config file.');
        $this->dump(['dto-generator' => ['config' => 3, 'failOnError' => true]]);
    }

    public function testShowsWarningsOfASuccessfulRun(): void
    {
        file_put_contents($this->root . '/gen/dto-generator.yaml', self::CONFIG . "extensionConfig:\n  nobody: {}\n");

        $output = $this->dump(['dto-generator' => ['config' => 'gen/dto-generator.yaml']]);

        self::assertStringStartsWith('dto-generator: warning gen/dto-generator.yaml#/extensionConfig/nobody: No loaded extension is named "nobody"', $output);
        self::assertStringEndsWith("dto-generator: written 1, deleted 0, unchanged 0.\n", $output);
    }

    /**
     * @param array<string, array<string, bool|int|string>|string> $extra
     * @param (Closure(Input): Output)|null $generator
     */
    private function dump(array $extra, ?Closure $generator = null, bool $scripts = true): string
    {
        $io = $this->io(false);
        $this->runPlugin($extra, $io, $generator, $scripts);

        return $io->getOutput();
    }

    private function io(bool $decorated): BufferIO
    {
        // Composer's own styles, "warning" among them, as its console application registers them.
        return new BufferIO('', OutputInterface::VERBOSITY_NORMAL, new OutputFormatter($decorated, Factory::createAdditionalStyles()));
    }

    /**
     * @param array<string, array<string, bool|int|string>|string> $extra
     * @param (Closure(Input): Output)|null $generator
     */
    private function runPlugin(array $extra, BufferIO $io, ?Closure $generator = null, bool $scripts = true): void
    {
        $composer = new Composer();
        $package = new RootPackage('acme/app', '1.0.0.0', '1.0.0');
        $package->setExtra($extra);

        $composer->setPackage($package);
        $composer->setEventDispatcher((new EventDispatcher($composer, $io))->setRunScripts($scripts));

        $plugin = new ComposerPlugin($generator);
        $plugin->activate($composer, $io);

        try {
            $plugin->onPostAutoloadDump(new Event(ScriptEvents::POST_AUTOLOAD_DUMP, $composer, $io));
        } finally {
            $plugin->deactivate($composer, $io);
            $plugin->uninstall($composer, $io);
        }
    }
}
