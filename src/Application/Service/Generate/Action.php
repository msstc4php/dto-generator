<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Service\Generate;

use MSSTC4PHP\DtoGenerator\Application\Config\GeneratorConfig;
use MSSTC4PHP\DtoGenerator\Application\Config\SourceConfig;
use MSSTC4PHP\DtoGenerator\Application\Port\CodeEmitter;
use MSSTC4PHP\DtoGenerator\Application\Port\FileWriter;
use MSSTC4PHP\DtoGenerator\Application\Port\WriteFailed;
use MSSTC4PHP\DtoGenerator\Application\Service\Config\Load\Action as LoadConfig;
use MSSTC4PHP\DtoGenerator\Application\Service\Config\Load\Input as ConfigInput;
use MSSTC4PHP\DtoGenerator\Application\Service\Model\Build\Action as BuildModel;
use MSSTC4PHP\DtoGenerator\Application\Service\Model\Build\Input as BuildInput;
use MSSTC4PHP\DtoGenerator\Application\Service\Schemas\Load\Action as LoadSchemas;
use MSSTC4PHP\DtoGenerator\Application\Service\Schemas\Load\Input as SchemasInput;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use MSSTC4PHP\DtoGenerator\Domain\Target\TargetProfile;

/**
 * The whole run (spec §3): config → schemas → IR → code → files. Nothing is written once any error is known.
 */
final class Action
{
    private LoadConfig $loadConfig;

    private LoadSchemas $loadSchemas;

    private BuildModel $buildModel;

    private CodeEmitter $emitter;

    private FileWriter $writer;

    public function __construct(LoadConfig $loadConfig, LoadSchemas $loadSchemas, BuildModel $buildModel, CodeEmitter $emitter, FileWriter $writer)
    {
        $this->loadConfig = $loadConfig;
        $this->loadSchemas = $loadSchemas;
        $this->buildModel = $buildModel;
        $this->emitter = $emitter;
        $this->writer = $writer;
    }

    public function __invoke(Input $input): Output
    {
        $diagnostics = new Diagnostics();
        $loaded = ($this->loadConfig)(new ConfigInput($input->configPath()));
        $diagnostics->merge($loaded->diagnostics());
        $config = $loaded->config();
        $target = $loaded->target();
        if (!$config instanceof GeneratorConfig || !$target instanceof TargetProfile || $diagnostics->hasErrors()) {
            return new Output(Status::from(Status::CONFIG_FAILED), $diagnostics, null, []);
        }

        $files = $this->files($config, $target, $diagnostics);
        if ($files === null) {
            return new Output(Status::from(Status::GENERATION_FAILED), $diagnostics, null, []);
        }

        $outputDirs = array_values(array_unique(array_map(static fn (SourceConfig $source): string => $source->outputDir(), $config->sources())));
        $plan = $this->writer->plan($outputDirs, $files);

        try {
            return $this->conclude($input->mode(), $plan, $files, $diagnostics);
        } finally {
            // plan() locks the output directories; apply() releases them, but a check or an error never applies.
            $this->writer->release();
        }
    }

    /**
     * @param list<GeneratedFile> $files
     */
    private function conclude(Mode $mode, WritePlan $plan, array $files, Diagnostics $diagnostics): Output
    {
        foreach ($plan->conflicts() as $path => $reason) {
            $diagnostics->error($reason, new SchemaLocation($path));
        }

        if ($plan->conflicts() !== []) {
            return new Output(Status::from(Status::GENERATION_FAILED), $diagnostics, $plan, $files);
        }

        if ($mode->value() === Mode::CHECK) {
            return new Output(Status::from($plan->hasChanges() ? Status::OUT_OF_DATE : Status::OK), $diagnostics, $plan, $files);
        }

        if ($mode->value() === Mode::WRITE) {
            try {
                $this->writer->apply($plan);
            } catch (WriteFailed $exception) {
                $diagnostics->error($exception->getMessage(), new SchemaLocation($exception->path()));

                return new Output(Status::from(Status::GENERATION_FAILED), $diagnostics, $plan, $files);
            }
        }

        return new Output(Status::from(Status::OK), $diagnostics, $plan, $files);
    }

    /**
     * Null when the schemas or the model have errors, since emitting them would be wasted work.
     *
     * @return list<GeneratedFile>|null
     */
    private function files(GeneratorConfig $config, TargetProfile $target, Diagnostics $diagnostics): ?array
    {
        $schemas = ($this->loadSchemas)(new SchemasInput($config));
        $diagnostics->merge($schemas->diagnostics());
        $model = ($this->buildModel)(new BuildInput($config, $target, $schemas->graph()));
        $diagnostics->merge($model->diagnostics());
        if ($diagnostics->hasErrors()) {
            return null;
        }

        $files = [];
        foreach ($model->classes() as $built) {
            $source = $config->sources()[$built->source()];
            $class = $built->model();
            // Build places every class directly in the namespace of its source.
            $files[] = new GeneratedFile($source->outputDir(), $class->name()->shortName() . '.php', $this->emitter->emit($class, $target));
        }

        return $files;
    }
}
