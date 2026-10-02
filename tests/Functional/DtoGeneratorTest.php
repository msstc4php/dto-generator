<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Functional;

use MSSTC4PHP\DtoGenerator\DtoGenerator;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;

final class DtoGeneratorTest extends TestCase
{
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
        $application = new Application();
        $application->add(new class extends Command {
            protected function configure(): void
            {
                $this->setName('boom');
            }

            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                throw new RuntimeException('Disk on fire.');
            }
        });
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
        $output = new class extends BufferedOutput implements ConsoleOutputInterface {
            public BufferedOutput $errors;

            public function __construct()
            {
                parent::__construct();
                $this->errors = new BufferedOutput();
            }

            public function getErrorOutput(): OutputInterface
            {
                return $this->errors;
            }

            public function setErrorOutput(OutputInterface $error): void
            {
            }

            public function section(): ConsoleSectionOutput
            {
                throw new RuntimeException('No sections here.');
            }
        };

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
        $application = new Application();
        $application->add(new class extends Command {
            protected function configure(): void
            {
                $this->setName('boom');
            }

            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                throw new RuntimeException('Disk on fire.');
            }
        });
        $verbose = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);
        $veryVerbose = new BufferedOutput(OutputInterface::VERBOSITY_VERY_VERBOSE);

        self::assertSame(2, DtoGenerator::run(new ArrayInput(['command' => 'boom']), $verbose, $application));
        self::assertSame(2, DtoGenerator::run(new ArrayInput(['command' => 'boom']), $veryVerbose, $application));
        $short = $verbose->fetch();
        self::assertStringStartsWith("error: Disk on fire.\nRuntimeException in ", $short);
        self::assertStringNotContainsString('#0 ', $short);
        self::assertStringContainsString("\n#0 ", $veryVerbose->fetch());
    }
}
