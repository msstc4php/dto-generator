<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator;

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

/**
 * The composition root and the PHP API: `DtoGenerator::generator()(new Input($config, Mode::from(Mode::WRITE)))`.
 */
final class DtoGenerator
{
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
        $application = new Application('dto-generator', 'dev');
        $application->add(new GenerateCommand(self::generator(), $workingDirectory ?? (string) getcwd()));

        return $application;
    }
}
