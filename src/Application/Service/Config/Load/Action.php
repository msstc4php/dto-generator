<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Service\Config\Load;

use MSSTC4PHP\DtoGenerator\Application\Config\ConfigFactory;
use MSSTC4PHP\DtoGenerator\Application\Config\GeneratorConfig;
use MSSTC4PHP\DtoGenerator\Application\Config\TargetResolver;
use MSSTC4PHP\DtoGenerator\Application\Port\DocumentLoader;
use MSSTC4PHP\DtoGenerator\Application\Port\DocumentLoadFailed;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;

final class Action
{
    private DocumentLoader $loader;

    private ConfigFactory $factory;

    private TargetResolver $targets;

    public function __construct(DocumentLoader $loader, ConfigFactory $factory, TargetResolver $targets)
    {
        $this->loader = $loader;
        $this->factory = $factory;
        $this->targets = $targets;
    }

    public function __invoke(Input $input): Output
    {
        $diagnostics = new Diagnostics();

        try {
            $document = $this->loader->load($input->configPath());
        } catch (DocumentLoadFailed $exception) {
            $diagnostics->error($exception->getMessage(), new SchemaLocation($input->configPath()));

            return new Output(null, null, $diagnostics);
        }

        $config = $this->factory->create($document->root(), $document->path(), $diagnostics);
        $target = $config instanceof GeneratorConfig ? $this->targets->resolve($config, $diagnostics) : null;

        return new Output($config, $target, $diagnostics);
    }
}
