<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Service\Generate;

use MSSTC4PHP\DtoGenerator\Application\Config\GeneratorConfig;
use MSSTC4PHP\DtoGenerator\Application\Config\SourceConfig;
use MSSTC4PHP\DtoGenerator\Application\Port\ClassVerifier;
use MSSTC4PHP\DtoGenerator\Application\Port\ClassVerifierLocator;
use MSSTC4PHP\DtoGenerator\Application\Port\CodeEmitter;
use MSSTC4PHP\DtoGenerator\Application\Port\FileWriter;
use MSSTC4PHP\DtoGenerator\Application\Port\ProjectPackages;
use MSSTC4PHP\DtoGenerator\Application\Port\ProjectPackagesUnusable;
use MSSTC4PHP\DtoGenerator\Application\Port\WriteFailed;
use MSSTC4PHP\DtoGenerator\Application\Service\Config\Load\Action as LoadConfig;
use MSSTC4PHP\DtoGenerator\Application\Service\Config\Load\Input as ConfigInput;
use MSSTC4PHP\DtoGenerator\Application\Service\Extension\Load\Action as LoadExtensions;
use MSSTC4PHP\DtoGenerator\Application\Service\Extension\Load\Input as ExtensionsInput;
use MSSTC4PHP\DtoGenerator\Application\Service\Model\Build\Action as BuildModel;
use MSSTC4PHP\DtoGenerator\Application\Service\Model\Build\Input as BuildInput;
use MSSTC4PHP\DtoGenerator\Application\Service\Model\Enrich\Action as EnrichModel;
use MSSTC4PHP\DtoGenerator\Application\Service\Model\Enrich\Input as EnrichInput;
use MSSTC4PHP\DtoGenerator\Application\Service\Schemas\Load\Action as LoadSchemas;
use MSSTC4PHP\DtoGenerator\Application\Service\Schemas\Load\Input as SchemasInput;
use MSSTC4PHP\DtoGenerator\Contract\InstalledPackages;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassType;
use MSSTC4PHP\DtoGenerator\Domain\Model\TypeModel;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use MSSTC4PHP\DtoGenerator\Domain\Target\TargetProfile;

/**
 * The whole run (spec §3): config → schemas → IR → code → files. Nothing is written once any error is known.
 *
 * @api
 */
final class Action
{
    private LoadConfig $loadConfig;

    private ClassVerifierLocator $verifiers;

    private ProjectPackages $packages;

    private LoadExtensions $loadExtensions;

    private LoadSchemas $loadSchemas;

    private BuildModel $buildModel;

    private EnrichModel $enrichModel;

    private CodeEmitter $emitter;

    private FileWriter $writer;

    public function __construct(
        LoadConfig $loadConfig,
        ClassVerifierLocator $verifiers,
        ProjectPackages $packages,
        LoadExtensions $loadExtensions,
        LoadSchemas $loadSchemas,
        BuildModel $buildModel,
        EnrichModel $enrichModel,
        CodeEmitter $emitter,
        FileWriter $writer
    ) {
        $this->loadConfig = $loadConfig;
        $this->verifiers = $verifiers;
        $this->packages = $packages;
        $this->loadExtensions = $loadExtensions;
        $this->loadSchemas = $loadSchemas;
        $this->buildModel = $buildModel;
        $this->enrichModel = $enrichModel;
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
        $verifier = $config instanceof GeneratorConfig ? $this->verifier($config, $diagnostics) : null;
        if (!$config instanceof GeneratorConfig || !$target instanceof TargetProfile || $diagnostics->hasErrors()) {
            return new Output(Status::from(Status::CONFIG_FAILED), $diagnostics, null, []);
        }

        $files = $this->files($config, $target, $verifier, $diagnostics);
        if ($files === null) {
            return new Output(Status::from(Status::GENERATION_FAILED), $diagnostics, null, []);
        }

        $outputDirs = array_values(array_unique(array_map(static fn (SourceConfig $source): string => $source->outputDir(), $config->sources())));
        try {
            return $this->conclude($input->mode(), $this->writer->plan($outputDirs, $files), $files, $diagnostics);
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
     * Versions are a courtesy to extensions (spec §8), so a lock that cannot be used only warns.
     */
    private function packages(GeneratorConfig $config, Diagnostics $diagnostics): InstalledPackages
    {
        try {
            return $this->packages->read($config->baseDir());
        } catch (ProjectPackagesUnusable $exception) {
            $diagnostics->warning(
                sprintf('The installed package versions are unknown: the file %s.', $exception->reason()),
                new SchemaLocation($exception->file()),
            );

            return new InstalledPackages();
        }
    }

    /**
     * verifyClasses (spec §4): "auto" verifies when the consumer's Composer autoloader is found and the environment does
     * not forbid it; true insists on the autoloader.
     */
    private function verifier(GeneratorConfig $config, Diagnostics $diagnostics): ?ClassVerifier
    {
        $setting = $config->extensions()->verifyClasses();
        if ($setting === false || ($setting === null && $this->verifiers->isDisabledByEnvironment())) {
            return null;
        }

        $verifier = $this->verifiers->locate($config->baseDir());
        if ($setting === true && !$verifier instanceof ClassVerifier) {
            $diagnostics->error(
                sprintf('"verifyClasses" is true, but the Composer project at or above %s has no autoload.php in its vendor-dir.', $config->baseDir()),
                $config->location()->child('verifyClasses'),
            );
        }

        return $verifier;
    }

    /**
     * Null when the schemas or the model have errors, since emitting them would be wasted work.
     *
     * @return list<GeneratedFile>|null
     */
    private function files(GeneratorConfig $config, TargetProfile $target, ?ClassVerifier $verifier, Diagnostics $diagnostics): ?array
    {
        $extensions = ($this->loadExtensions)(new ExtensionsInput($config));
        $diagnostics->merge($extensions->diagnostics());
        $registry = $extensions->registry();
        $schemas = ($this->loadSchemas)(new SchemasInput($config));
        $diagnostics->merge($schemas->diagnostics());
        $formats = $registry->formats(array_map(static fn (ClassName $class): TypeModel => new ClassType($class), $config->formats()));
        $aliases = array_keys($config->extensions()->aliases());
        $model = ($this->buildModel)(new BuildInput($config, $target, $schemas->graph(), $formats, $aliases));
        $diagnostics->merge($model->diagnostics());
        $enriched = ($this->enrichModel)(new EnrichInput($model->classes(), $model->enums(), $schemas->graph(), $target, $registry, $this->packages($config, $diagnostics), $verifier));
        $diagnostics->merge($enriched->diagnostics());
        if ($diagnostics->hasErrors()) {
            return null;
        }

        $files = [];
        foreach ($enriched->classes() as $built) {
            $source = $config->sources()[$built->source()];
            $class = $built->model();
            // Build places every class directly in the namespace of its source.
            $files[] = new GeneratedFile(
                $source->outputDir(),
                $class->name()->shortName() . '.php',
                $this->emitter->emit($class, $target, $model->inheritedProperties($class), $model->parentOf($class)),
            );
        }

        foreach ($model->enums() as $built) {
            $source = $config->sources()[$built->source()];
            $enum = $built->model();
            $files[] = new GeneratedFile($source->outputDir(), $enum->name()->shortName() . '.php', $this->emitter->emitEnum($enum, $target));
        }

        return $files;
    }
}
