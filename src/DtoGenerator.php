<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator;

use Composer\InstalledVersions;
use MSSTC4PHP\DtoGenerator\Application\Config\ConfigFactory;
use MSSTC4PHP\DtoGenerator\Application\Config\TargetResolver;
use MSSTC4PHP\DtoGenerator\Application\Service\Config\Load\Action as LoadConfig;
use MSSTC4PHP\DtoGenerator\Application\Service\Generate\Action as Generate;
use MSSTC4PHP\DtoGenerator\Application\Service\Model\Build\Action as BuildModel;
use MSSTC4PHP\DtoGenerator\Application\Service\Schemas\Load\Action as LoadSchemas;
use MSSTC4PHP\DtoGenerator\Domain\Builder\NameResolver;
use MSSTC4PHP\DtoGenerator\Domain\Builder\SchemaParser;
use MSSTC4PHP\DtoGenerator\Infrastructure\Document\FileDocumentLoader;
use MSSTC4PHP\DtoGenerator\Infrastructure\Emitter\PhpParserEmitter;
use MSSTC4PHP\DtoGenerator\Infrastructure\Environment\ComposerJsonPhpConstraint;
use MSSTC4PHP\DtoGenerator\Infrastructure\Writer\FilesystemWriter;
use MSSTC4PHP\DtoGenerator\Presentation\Cli\GenerateCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\CommandLoader\FactoryCommandLoader;
use Symfony\Component\Console\Exception\ExceptionInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * The composition root and the PHP API: `DtoGenerator::generator()(new Input($config, Mode::from(Mode::WRITE)))`.
 */
final class DtoGenerator
{
    private const PACKAGE = 'msstc4php/dto-generator';

    private function __construct()
    {
    }

    public static function generator(): Generate
    {
        $loader = new FileDocumentLoader();

        return new Generate(
            new LoadConfig($loader, new ConfigFactory(), new TargetResolver(new ComposerJsonPhpConstraint())),
            new LoadSchemas($loader, new SchemaParser()),
            new BuildModel(new NameResolver()),
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
        $output ??= new ConsoleOutput();
        $application ??= self::console();
        $application->setAutoExit(false);
        $application->setCatchExceptions(false);

        try {
            return $application->run($input, $output);
        } catch (ExceptionInterface $exception) {
            self::errorOutput($output)->writeln('error: ' . $exception->getMessage());

            return 3;
        } catch (Throwable $exception) {
            self::errorOutput($output)->writeln('error: ' . $exception->getMessage());

            return 2;
        }
    }

    private static function version(): string
    {
        return InstalledVersions::getPrettyVersion(self::PACKAGE) ?? 'dev';
    }

    private static function errorOutput(OutputInterface $output): OutputInterface
    {
        return $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
    }
}
