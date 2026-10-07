<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator;

use Composer\InstalledVersions;
use MSSTC4PHP\DtoGenerator\Application\Config\ConfigFactory;
use MSSTC4PHP\DtoGenerator\Application\Config\TargetResolver;
use MSSTC4PHP\DtoGenerator\Application\Service\Config\Load\Action as LoadConfig;
use MSSTC4PHP\DtoGenerator\Application\Service\Extension\Load\Action as LoadExtensions;
use MSSTC4PHP\DtoGenerator\Application\Service\Generate\Action as Generate;
use MSSTC4PHP\DtoGenerator\Application\Service\Model\Build\Action as BuildModel;
use MSSTC4PHP\DtoGenerator\Application\Service\Model\Enrich\Action as EnrichModel;
use MSSTC4PHP\DtoGenerator\Application\Service\Schemas\Load\Action as LoadSchemas;
use MSSTC4PHP\DtoGenerator\Domain\Builder\NameResolver;
use MSSTC4PHP\DtoGenerator\Domain\Builder\SchemaParser;
use MSSTC4PHP\DtoGenerator\Extension\CustomAttributes\CustomAttributes;
use MSSTC4PHP\DtoGenerator\Infrastructure\Document\FileDocumentLoader;
use MSSTC4PHP\DtoGenerator\Infrastructure\Emitter\PhpParserEmitter;
use MSSTC4PHP\DtoGenerator\Infrastructure\Environment\AutoloadClassVerifierLocator;
use MSSTC4PHP\DtoGenerator\Infrastructure\Environment\ComposerJsonPhpConstraint;
use MSSTC4PHP\DtoGenerator\Infrastructure\Environment\ComposerLockPackages;
use MSSTC4PHP\DtoGenerator\Infrastructure\Environment\InstalledJsonExtensionDiscovery;
use MSSTC4PHP\DtoGenerator\Infrastructure\Extension\ClassExtensionLoader;
use MSSTC4PHP\DtoGenerator\Infrastructure\Writer\FilesystemWriter;
use MSSTC4PHP\DtoGenerator\Presentation\Cli\ErrorOutput;
use MSSTC4PHP\DtoGenerator\Presentation\Cli\GenerateCommand;
use MSSTC4PHP\DtoGenerator\Presentation\Cli\JsonReport;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\CommandLoader\FactoryCommandLoader;
use Symfony\Component\Console\Exception\ExceptionInterface;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * The composition root and the PHP API: `DtoGenerator::generator()(new Input($config, Mode::from(Mode::WRITE)))`.
 */
final class DtoGenerator
{
    private const MEMORY_LIMIT = '1G';

    private const PACKAGE = 'msstc4php/dto-generator';

    private function __construct()
    {
    }

    public static function generator(): Generate
    {
        $loader = new FileDocumentLoader();

        return new Generate(
            new LoadConfig($loader, new ConfigFactory(), new TargetResolver(new ComposerJsonPhpConstraint())),
            new AutoloadClassVerifierLocator(),
            new ComposerLockPackages(),
            new LoadExtensions(new ClassExtensionLoader(), static fn (array $aliases): array => [new CustomAttributes($aliases)], new InstalledJsonExtensionDiscovery()),
            new LoadSchemas($loader, new SchemaParser()),
            new BuildModel(new NameResolver()),
            new EnrichModel(),
            new PhpParserEmitter(),
            new FilesystemWriter(),
        );
    }

    /**
     * @param string|null $workingDirectory where the default config is looked up and paths are shown from; the process's by default
     */
    public static function console(?string $workingDirectory = null): Application
    {
        $application = new Application('dto-generator', self::version());
        $directory = $workingDirectory ?? (string) getcwd();
        // A command loader works the same on symfony/console 5.4 to 7.x, where add() is deprecated in favour of addCommand().
        $application->setCommandLoader(new FactoryCommandLoader([
            'generate' => static fn (): GenerateCommand => new GenerateCommand(self::generator(), $directory),
        ]));

        return $application;
    }

    /**
     * Runs the console so that exit code 1 keeps meaning "out of date" (spec §9.2): Symfony itself answers 1 to
     * a mistyped option or an uncaught exception, which here become 3 and 2.
     */
    public static function run(?InputInterface $input = null, ?OutputInterface $output = null, ?Application $application = null): int
    {
        self::prepareRuntime();
        $input ??= new ArgvInput();
        $output ??= new ConsoleOutput();
        $application = self::withoutAutoExit($application ?? self::console());
        $application->setCatchExceptions(false);

        return self::guard($input, $output, static fn (): int => $application->run($input, $output));
    }

    /**
     * Runs a console call with the generator's exit codes for its failures: 3 for a usage error (as JSON with
     * --format=json), 2 for anything else. An application that wraps the generate command keeps the CLI's contract.
     *
     * @param callable(): int $run
     */
    public static function guard(InputInterface $input, OutputInterface $output, callable $run): int
    {
        try {
            return $run();
        } catch (ExceptionInterface $exception) {
            // getParameterOption() is documented as "mixed", which PHPStan reads as a class on phpVersion 70400;
            // json_encode() takes any value and gives a plain string to compare.
            if (json_encode($input->getParameterOption('--format')) === '"json"') {
                $output->writeln(JsonReport::failure($exception->getMessage()), OutputInterface::OUTPUT_RAW);
            } else {
                ErrorOutput::of($output)->writeln('error: ' . $exception->getMessage());
            }

            return 3;
        } catch (Throwable $exception) {
            $errors = ErrorOutput::of($output);
            $errors->writeln('error: ' . $exception->getMessage());
            $errors->writeln(sprintf('%s in %s:%d', get_class($exception), $exception->getFile(), $exception->getLine()), OutputInterface::VERBOSITY_VERBOSE);
            $errors->writeln($exception->getTraceAsString(), OutputInterface::VERBOSITY_VERY_VERBOSE);

            return 2;
        }
    }

    /**
     * PHP's default 128M does not hold the model of a large spec. A fatal error, out of memory included, still ends
     * with exit code 2 rather than PHP's 255 (PHP 7.4 ignores the exit of a shutdown function after a fatal error
     * raised inside a function, so 255 stays there), and its message goes to stderr, so stdout stays a valid JSON
     * report.
     */
    private static function prepareRuntime(): void
    {
        if (self::bytes(ini_get('memory_limit')) < self::bytes(self::MEMORY_LIMIT)) {
            ini_set('memory_limit', self::MEMORY_LIMIT);
        }

        ini_set('display_errors', 'stderr');
        register_shutdown_function([self::class, 'exitOnFatalError']);
    }

    /**
     * @internal the shutdown function of a run
     */
    public static function exitOnFatalError(): void
    {
        $error = error_get_last();
        if ($error !== null && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            exit(2);
        }
    }

    /**
     * @return int PHP_INT_MAX for no limit
     */
    private static function bytes(string $limit): int
    {
        if ($limit === '-1') {
            return PHP_INT_MAX;
        }

        $units = ['k' => 1024, 'm' => 1024 ** 2, 'g' => 1024 ** 3];

        return (int) $limit * ($units[strtolower(substr($limit, -1))] ?? 1);
    }

    private static function version(): string
    {
        return InstalledVersions::getPrettyVersion(self::PACKAGE) ?? 'dev';
    }

    /**
     * Without it, run() would exit the process instead of returning the code.
     */
    private static function withoutAutoExit(Application $application): Application
    {
        $application->setAutoExit(false);

        return $application;
    }
}
