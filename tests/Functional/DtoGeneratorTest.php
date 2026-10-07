<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Functional;

use MSSTC4PHP\DtoGenerator\DtoGenerator;
use MSSTC4PHP\DtoGenerator\Tests\Support\SplitOutput;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\CommandLoader\FactoryCommandLoader;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

final class DtoGeneratorTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $settings = [];

    protected function setUp(): void
    {
        $this->settings = ['memory_limit' => ini_get('memory_limit'), 'display_errors' => ini_get('display_errors')];
    }

    protected function tearDown(): void
    {
        foreach ($this->settings as $name => $value) {
            ini_set($name, (string) $value);
        }

        putenv('DTO_GENERATOR_MEMORY_LIMIT');
    }

    /**
     * @dataProvider memoryLimits
     */
    public function testRaisesALowMemoryLimitForTheRun(string $before, string $after): void
    {
        ini_set('memory_limit', $before);

        DtoGenerator::run(new ArrayInput(['command' => 'nope']), new BufferedOutput());

        self::assertSame($after, ini_get('memory_limit'));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function memoryLimits(): array
    {
        return [
            'megabytes below' => ['128M', '1G'],
            'just below in bytes' => ['1073741823', '1G'],
            'exactly in bytes' => ['1073741824', '1073741824'],
            'just below in kilobytes' => ['1048575K', '1G'],
            'exactly in kilobytes' => ['1048576K', '1048576K'],
            'just below in megabytes' => ['1023m', '1G'],
            'exactly in megabytes' => ['1024M', '1024M'],
            'gigabytes above' => ['2G', '2G'],
            'unlimited' => ['-1', '-1'],
        ];
    }

    public function testTakesTheMemoryLimitFromTheEnvironment(): void
    {
        ini_set('memory_limit', '2G');
        putenv('DTO_GENERATOR_MEMORY_LIMIT=768M');

        DtoGenerator::run(new ArrayInput(['command' => 'nope']), new BufferedOutput());

        self::assertSame('768M', ini_get('memory_limit'));
    }

    /**
     * @dataProvider displays
     */
    public function testMovesShownPhpErrorsToStderr(string $before, string $after): void
    {
        ini_set('display_errors', $before);

        DtoGenerator::run(new ArrayInput(['command' => 'nope']), new BufferedOutput());

        self::assertSame($after, ini_get('display_errors'));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function displays(): array
    {
        return [
            'on' => ['1', 'stderr'],
            'on in words' => ['On', 'stderr'],
            'stdout' => ['stdout', 'stderr'],
            'off' => ['0', '0'],
            'already stderr' => ['stderr', 'stderr'],
        ];
    }

    public function testExitsWithTheGenerationCodeOnAFatalError(): void
    {
        // A request beyond the limit fails at once, without allocating anything.
        $script = sprintf(<<<'PHP_WRAP'
            <?php
            require %s;
            $application = new Symfony\Component\Console\Application();
            $application->setCommandLoader(new Symfony\Component\Console\CommandLoader\FactoryCommandLoader([
                'boom' => static fn () => new class('boom') extends Symfony\Component\Console\Command\Command {
                    protected function execute(Symfony\Component\Console\Input\InputInterface $input, Symfony\Component\Console\Output\OutputInterface $output): int
                    {
                        return strlen(str_repeat('x', 4 * 1024 * 1024 * 1024));
                    }
                },
            ]));
            exit(MSSTC4PHP\DtoGenerator\DtoGenerator::run(new Symfony\Component\Console\Input\ArrayInput(['command' => 'boom']), null, $application));
            PHP_WRAP, var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true));
        $file = tempnam(sys_get_temp_dir(), 'dto-generator-fatal-');
        self::assertIsString($file);
        file_put_contents($file, $script);

        $process = proc_open([PHP_BINARY, '-d', 'memory_limit=2G', '-d', 'display_errors=1', '-d', 'log_errors=0', $file], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        $code = proc_close($process);
        unlink($file);

        // PHP 7.4 ignores the exit of a shutdown function after a fatal error raised inside a function.
        self::assertSame(PHP_VERSION_ID >= 80000 ? 2 : 255, $code, (string) $stderr);
        self::assertSame('', $stdout);
        self::assertStringContainsString('Allowed memory size of 2147483648 bytes exhausted', (string) $stderr);
    }

    public function testMapsAnInputErrorToTheConfigExitCode(): void
    {
        $output = new BufferedOutput();

        self::assertSame(3, DtoGenerator::run(new ArrayInput(['command' => 'generate', '--bogus' => true]), $output));
        self::assertSame("error: The \"--bogus\" option does not exist.\n", $output->fetch());
    }

    public function testMapsAnUnknownCommandToTheConfigExitCode(): void
    {
        self::assertSame(3, DtoGenerator::run(new ArrayInput(['command' => 'nope']), new BufferedOutput()));
    }

    public function testMapsAnUnexpectedFailureToTheGenerationExitCode(): void
    {
        $application = $this->failingApplication();
        $output = new BufferedOutput();

        self::assertSame(2, DtoGenerator::run(new ArrayInput(['command' => 'boom']), $output, $application));
        self::assertSame("error: Disk on fire.\n", $output->fetch());
    }

    public function testNamesItself(): void
    {
        $console = DtoGenerator::console('/tmp');

        self::assertSame('dto-generator', $console->getName());
        self::assertNotSame('', $console->getVersion());
        self::assertNotSame('dev', $console->getVersion());
        self::assertTrue($console->has('generate'));
    }

    public function testWritesErrorsToStderrOfAConsoleOutput(): void
    {
        $output = new SplitOutput();

        self::assertSame(3, DtoGenerator::run(new ArrayInput(['command' => 'nope']), $output));
        self::assertSame('', $output->fetch());
        self::assertStringStartsWith('error: Command "nope" is not defined.', $output->errors->fetch());
    }

    public function testWritesUsageErrorsToAPlainOutput(): void
    {
        $output = new BufferedOutput();

        self::assertSame(3, DtoGenerator::run(new ArrayInput(['command' => 'generate', '--check' => true, '--dry-run' => true]), $output));
        self::assertSame("error: --check and --dry-run cannot be combined.\n", $output->fetch());
    }

    public function testReportsInputErrorsAsJsonWhenAsked(): void
    {
        $output = new BufferedOutput();

        self::assertSame(3, DtoGenerator::run(new ArrayInput(['command' => 'generate', '--bogus' => true, '--format' => 'json']), $output));
        self::assertSame(
            ['status' => 'config-failed', 'diagnostics' => [['severity' => 'error', 'location' => '', 'message' => 'The "--bogus" option does not exist.']], 'changes' => []],
            json_decode($output->fetch(), true),
        );
    }

    public function testShowsTheExceptionClassWhenVerbose(): void
    {
        $application = $this->failingApplication();
        $verbose = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);
        $veryVerbose = new BufferedOutput(OutputInterface::VERBOSITY_VERY_VERBOSE);

        self::assertSame(2, DtoGenerator::run(new ArrayInput(['command' => 'boom']), $verbose, $application));
        self::assertSame(2, DtoGenerator::run(new ArrayInput(['command' => 'boom']), $veryVerbose, $application));
        $short = $verbose->fetch();
        self::assertStringStartsWith("error: Disk on fire.\nRuntimeException in ", $short);
        self::assertStringNotContainsString('#0 ', $short);
        self::assertStringContainsString("\n#0 ", $veryVerbose->fetch());
    }

    public function testPrintsJsonVerbatim(): void
    {
        $output = new BufferedOutput();

        DtoGenerator::run(new ArrayInput(['command' => 'no\\<pe<comment>x</comment>', '--format' => 'json']), $output);

        $report = json_decode($output->fetch(), true);
        self::assertIsArray($report);
        self::assertIsArray($report['diagnostics']);
        self::assertIsArray($report['diagnostics'][0]);
        self::assertIsString($report['diagnostics'][0]['message']);
        self::assertStringContainsString('no\\<pe<comment>x</comment>', $report['diagnostics'][0]['message']);
    }

    public function testMapsTheFailuresOfAnyRunToTheGeneratorsExitCodes(): void
    {
        $usage = new BufferedOutput();
        $json = new BufferedOutput();
        $crash = new BufferedOutput();

        self::assertSame(3, DtoGenerator::guard(new ArrayInput([]), $usage, static function (): int {
            throw new InvalidOptionException('The "--chek" option does not exist.');
        }));
        self::assertSame(3, DtoGenerator::guard(new ArrayInput(['--format' => 'json']), $json, static function (): int {
            throw new InvalidOptionException('The "--chek" option does not exist.');
        }));
        self::assertSame(2, DtoGenerator::guard(new ArrayInput([]), $crash, static function (): int {
            throw new RuntimeException('boom');
        }));
        self::assertSame(1, DtoGenerator::guard(new ArrayInput([]), new BufferedOutput(), static fn (): int => 1));

        self::assertStringContainsString('error: The "--chek" option does not exist.', $usage->fetch());
        self::assertStringContainsString('"status": "config-failed"', $json->fetch());
        self::assertStringContainsString('error: boom', $crash->fetch());
    }

    /**
     * An application whose only command, "boom", throws; a command loader works on symfony/console 5.4 to 8, where
     * add() is gone.
     */
    private function failingApplication(): Application
    {
        $application = new Application();
        $application->setCommandLoader(new FactoryCommandLoader([
            'boom' => static fn (): Command => new class('boom') extends Command {
                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    throw new RuntimeException('Disk on fire.');
                }
            },
        ]));

        return $application;
    }
}
