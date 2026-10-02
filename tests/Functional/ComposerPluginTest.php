<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Functional;

use Composer\Composer;
use Composer\Config;
use Composer\EventDispatcher\EventDispatcher;
use Composer\Factory;
use Composer\IO\BufferIO;
use Composer\IO\IOInterface;
use Composer\Package\RootPackage;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Composer\Util\ProcessExecutor;
use FilesystemIterator;
use LogicException;
use MSSTC4PHP\DtoGenerator\ComposerPlugin;
use MSSTC4PHP\DtoGenerator\Tests\Support\Extensions\PackageVersionExtension;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The plugin runs this repository's bin/dto-generator, as a project runs its vendor/bin proxy.
 */
final class ComposerPluginTest extends TestCase
{
    private const CONFIG = "version: 1\ntarget: {php: '8.2'}\nverifyClasses: false\nsources:\n  - {spec: api.yaml, namespace: App\\Dto, outputDir: out}\n";

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
        putenv('COMPOSER');
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

    public function testDoesNothingWithoutItsExtra(): void
    {
        self::assertSame('', $this->dump([]));
        self::assertDirectoryDoesNotExist($this->root . '/gen/out');
    }

    public function testGeneratesFromAConfigRelativeToTheProject(): void
    {
        self::assertSame("dto-generator: Written: 1, deleted: 0, unchanged: 0.\n", $this->dump(['dto-generator' => ['config' => 'gen/dto-generator.yaml']]));
        self::assertFileExists($this->root . '/gen/out/Pet.php');
        self::assertSame("dto-generator: Written: 0, deleted: 0, unchanged: 1.\n", $this->dump(['dto-generator' => ['config' => 'gen/dto-generator.yaml']]));
    }

    public function testTakesTheProjectFromTheComposerVariable(): void
    {
        mkdir($this->root . '/sub');
        file_put_contents($this->root . '/sub/dto-generator.yaml', self::CONFIG);
        file_put_contents($this->root . '/sub/api.yaml', self::SPEC);
        putenv('COMPOSER=sub/composer.json');

        $this->dump(['dto-generator' => ['config' => 'dto-generator.yaml']]);

        self::assertFileExists($this->root . '/sub/out/Pet.php');
    }

    public function testPassesAbsoluteConfigPathsAsTheyAre(): void
    {
        $this->dump(['dto-generator' => ['config' => $this->root . '/gen/dto-generator.yaml']]);
        self::assertFileExists($this->root . '/gen/out/Pet.php');

        self::assertStringStartsWith('dto-generator: error C:/nowhere/dto.yaml: ', $this->dump(['dto-generator' => ['config' => 'C:\nowhere\dto.yaml']]));
    }

    public function testLoadsExtensionsThroughTheProjectsAutoloader(): void
    {
        file_put_contents($this->root . '/gen/dto-generator.yaml', self::CONFIG . "extensions: ['" . PackageVersionExtension::class . "']\n");

        $this->dump(['dto-generator' => ['config' => 'gen/dto-generator.yaml']]);

        self::assertStringContainsString("#[\\App\\Attr\\Validator('none')]", (string) file_get_contents($this->root . '/gen/out/Pet.php'));
    }

    public function testWarnsAboutFailuresUnlessToldToFail(): void
    {
        file_put_contents($this->root . '/gen/api.yaml', "openapi: 3.1.0\ncomponents:\n  schemas:\n    Pet: {type: object, properties: {owner: {\$ref: '#/components/schemas/Gone'}}}\n");

        $output = $this->dump(['dto-generator' => ['config' => 'gen/dto-generator.yaml']]);

        self::assertStringContainsString('dto-generator: error gen/api.yaml#/components/schemas/Pet/properties/owner: $ref "#/components/schemas/Gone" does not resolve', $output);
        self::assertStringEndsWith("dto-generator: Generation failed: 1 error(s).\ndto-generator: the generator exited with code 2.\n", $output);
        self::assertDirectoryDoesNotExist($this->root . '/gen/out');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('dto-generator: the generator exited with code 2.');
        $this->dump(['dto-generator' => ['config' => 'gen/dto-generator.yaml', 'failOnError' => true]]);
    }

    public function testWarnsAboutAGeneratorThatCannotFinishUnlessToldToFail(): void
    {
        mkdir($this->root . '/slow-bin');
        file_put_contents($this->root . '/slow-bin/dto-generator', "<?php\nsleep(5);\n");
        $timeout = ProcessExecutor::getTimeout();
        ProcessExecutor::setTimeout(1);

        try {
            $output = $this->dump(['dto-generator' => ['config' => 'gen/dto-generator.yaml']], $this->root . '/slow-bin');
            self::assertStringStartsWith('dto-generator: the generator could not run: ', $output);
            self::assertStringContainsString('exceeded the timeout of 1 seconds', $output);

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('dto-generator: the generator could not run: ');
            $this->dump(['dto-generator' => ['config' => 'gen/dto-generator.yaml', 'failOnError' => true]], $this->root . '/slow-bin');
        } finally {
            ProcessExecutor::setTimeout($timeout);
        }
    }

    public function testShowsTheOutputOfAFailureAsErrorsWhenItFailsTheCommand(): void
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

    public function testShowsTheWarningsOfASuccessfulRunAsWarningsEvenWhenFailing(): void
    {
        file_put_contents($this->root . '/gen/dto-generator.yaml', self::CONFIG . "extensionConfig:\n  nobody: {}\n");
        $io = $this->io(true);

        $this->runPlugin(['dto-generator' => ['config' => 'gen/dto-generator.yaml', 'failOnError' => true]], $io);

        self::assertStringStartsWith("\033[30;43mdto-generator: warning ", $io->getOutput());
    }

    public function testKeepsAngleBracketsOfTheOutputAsText(): void
    {
        self::assertStringContainsString('missing<b>.yaml', $this->dump(['dto-generator' => ['config' => 'missing<b>.yaml']]));
    }

    public function testShowsTheWarningsOfASuccessfulRun(): void
    {
        file_put_contents($this->root . '/gen/dto-generator.yaml', self::CONFIG . "extensionConfig:\n  nobody: {}\n");

        $output = $this->dump(['dto-generator' => ['config' => 'gen/dto-generator.yaml']]);

        self::assertStringStartsWith('dto-generator: warning gen/dto-generator.yaml#/extensionConfig/nobody: No loaded extension is named "nobody"', $output);
        self::assertStringEndsWith("dto-generator: Written: 1, deleted: 0, unchanged: 0.\n", $output);
    }

    public function testReportsSettingsItCannotUse(): void
    {
        self::assertSame(
            "dto-generator: extra.dto-generator must be an object with \"config\", the path of the config file.\n",
            $this->dump(['dto-generator' => 'gen/dto-generator.yaml']),
        );
        self::assertSame(
            "dto-generator: extra.dto-generator must be an object with \"config\", the path of the config file.\n",
            $this->dump(['dto-generator' => ['gen/dto-generator.yaml']]),
        );
        self::assertSame(
            "dto-generator: extra.dto-generator has no \"config\", so nothing is generated.\n",
            $this->dump(['dto-generator' => []]),
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
        $this->expectExceptionMessage('dto-generator: extra.dto-generator has no "config", so nothing is generated.');
        $this->dump(['dto-generator' => ['failOnError' => true]]);
    }

    public function testDoesNothingWhenComposerRunsWithoutScripts(): void
    {
        $composer = $this->composer(['dto-generator' => ['config' => 'gen/dto-generator.yaml']]);
        $io = $this->io(false);
        $composer->setEventDispatcher((new EventDispatcher($composer, $io))->setRunScripts(false));

        (new ComposerPlugin())->onPostAutoloadDump(new Event(ScriptEvents::POST_AUTOLOAD_DUMP, $composer, $io));

        self::assertSame('', $io->getOutput());
        self::assertDirectoryDoesNotExist($this->root . '/gen/out');
    }

    public function testRunsWhenTheScriptsFlagCannotBeRead(): void
    {
        $composer = $this->composer(['dto-generator' => ['config' => 'gen/dto-generator.yaml']]);
        $io = $this->io(false);
        $composer->setEventDispatcher(new class($composer, $io) extends EventDispatcher {
            public function __construct(Composer $composer, IOInterface $io)
            {
                parent::__construct($composer, $io);
                unset($this->runScripts);
            }

            /**
             * @return never
             */
            public function __get(string $name)
            {
                throw new LogicException('No ' . $name . ' in this Composer.');
            }
        });

        (new ComposerPlugin())->onPostAutoloadDump(new Event(ScriptEvents::POST_AUTOLOAD_DUMP, $composer, $io));

        self::assertFileExists($this->root . '/gen/out/Pet.php');
    }

    /**
     * @param array<string, array<array-key, bool|int|string>|string> $extra
     */
    private function dump(array $extra, ?string $binDir = null): string
    {
        $io = $this->io(false);
        $this->runPlugin($extra, $io, $binDir);

        return $io->getOutput();
    }

    private function io(bool $decorated): BufferIO
    {
        // Composer's own styles, "warning" among them, as its console application registers them.
        return new BufferIO('', OutputInterface::VERBOSITY_NORMAL, new OutputFormatter($decorated, Factory::createAdditionalStyles()));
    }

    /**
     * @param array<string, array<array-key, bool|int|string>|string> $extra
     */
    private function composer(array $extra, ?string $binDir = null): Composer
    {
        $composer = new Composer();
        $package = new RootPackage('acme/app', '1.0.0.0', '1.0.0');
        $package->setExtra($extra);

        $composer->setPackage($package);
        $config = new Config(false, $this->root);
        $config->merge(['config' => ['bin-dir' => $binDir ?? dirname(__DIR__, 2) . '/bin']]);

        $composer->setConfig($config);

        return $composer;
    }

    /**
     * @param array<string, array<array-key, bool|int|string>|string> $extra
     */
    private function runPlugin(array $extra, BufferIO $io, ?string $binDir = null): void
    {
        $composer = $this->composer($extra, $binDir);
        $composer->setEventDispatcher(new EventDispatcher($composer, $io));

        $plugin = new ComposerPlugin();
        $plugin->activate($composer, $io);

        try {
            $plugin->onPostAutoloadDump(new Event(ScriptEvents::POST_AUTOLOAD_DUMP, $composer, $io));
        } finally {
            $plugin->deactivate($composer, $io);
            $plugin->uninstall($composer, $io);
        }
    }
}
