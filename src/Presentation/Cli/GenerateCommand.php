<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Presentation\Cli;

use MSSTC4PHP\DtoGenerator\Application\Service\Generate\Action as Generate;
use MSSTC4PHP\DtoGenerator\Application\Service\Generate\FileChange;
use MSSTC4PHP\DtoGenerator\Application\Service\Generate\Input;
use MSSTC4PHP\DtoGenerator\Application\Service\Generate\Mode;
use MSSTC4PHP\DtoGenerator\Application\Service\Generate\Output;
use MSSTC4PHP\DtoGenerator\Application\Service\Generate\Status;
use MSSTC4PHP\DtoGenerator\Application\Service\Generate\WritePlan;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostic;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Path;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `dto-generator generate [--config=] [--check] [--dry-run] [--format=text|json]` with the exit codes of spec §9.2.
 * The report goes to stdout; in text mode, diagnostics go to stderr.
 */
final class GenerateCommand extends Command
{
    private const EXIT_CODES = [
        Status::OK => 0,
        Status::OUT_OF_DATE => 1,
        Status::GENERATION_FAILED => 2,
        Status::CONFIG_FAILED => 3,
    ];

    private const DEFAULT_CONFIGS = ['dto-generator.yaml', 'dto-generator.json'];

    private Generate $generate;

    private string $workingDirectory;

    private DiagnosticFormatter $formatter;

    public function __construct(Generate $generate, string $workingDirectory)
    {
        $this->generate = $generate;
        $this->workingDirectory = Path::normalize($workingDirectory);
        $this->formatter = new DiagnosticFormatter($workingDirectory);
        parent::__construct('generate');
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Generates DTO classes from the OpenAPI schemas named in the config.')
            ->addOption('config', null, InputOption::VALUE_REQUIRED, 'Config file (default: dto-generator.yaml, then dto-generator.json)')
            ->addOption('check', null, InputOption::VALUE_NONE, 'Write nothing; exit 1 when the output is out of date')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Write nothing; show what would change')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'text (default) or json')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // getOptions() is typed in every supported symfony/console; getOption() returns "mixed".
        $options = $input->getOptions();
        $format = $options['format'] ?? 'text';
        if ($format !== 'text' && $format !== 'json') {
            return $this->usageError($output, false, '--format must be "text" or "json".');
        }

        $json = $format === 'json';
        $check = $options['check'] === true;
        $dryRun = $options['dry-run'] === true;
        if ($check && $dryRun) {
            return $this->usageError($output, $json, '--check and --dry-run cannot be combined.');
        }

        $config = $this->configPath($options['config'] ?? null);
        if ($config === null) {
            return $this->usageError($output, $json, 'No dto-generator.yaml or dto-generator.json here; pass --config.');
        }

        $mode = Mode::from($check ? Mode::CHECK : ($dryRun ? Mode::DRY_RUN : Mode::WRITE));
        $result = ($this->generate)(new Input($config, $mode));
        if ($json) {
            $output->writeln($this->json($result->status()->value(), $this->diagnostics($result), $this->changes($result->plan())));
        } else {
            $this->text($result, $mode, $output);
        }

        return self::EXIT_CODES[$result->status()->value()];
    }

    /**
     * @param string|bool|int|float|array<array-key, mixed>|null $option
     */
    private function configPath($option): ?string
    {
        if (is_string($option) && $option !== '') {
            return Path::resolve($this->workingDirectory, $option);
        }

        foreach (self::DEFAULT_CONFIGS as $name) {
            $path = Path::resolve($this->workingDirectory, $name);
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    private function usageError(OutputInterface $output, bool $json, string $message): int
    {
        if ($json) {
            $output->writeln(ErrorOutput::json($message));
        } else {
            ErrorOutput::of($output)->writeln('error: ' . $message);
        }

        return self::EXIT_CODES[Status::CONFIG_FAILED];
    }

    private function text(Output $result, Mode $mode, OutputInterface $output): void
    {
        $errors = ErrorOutput::of($output);
        foreach ($result->diagnostics()->all() as $diagnostic) {
            $errors->writeln($this->formatter->line($diagnostic));
        }

        $errorCount = count($result->diagnostics()->errors());
        $status = $result->status()->value();
        if ($status === Status::CONFIG_FAILED || $status === Status::GENERATION_FAILED) {
            $errors->writeln(sprintf('%s failed: %d error(s).', $status === Status::CONFIG_FAILED ? 'Configuration' : 'Generation', $errorCount));

            return;
        }

        $plan = $result->plan();
        $counts = [FileChange::CREATE => 0, FileChange::UPDATE => 0, FileChange::DELETE => 0, FileChange::UNCHANGED => 0];
        foreach ($this->planChanges($plan) as $change) {
            $counts[$change->kind()]++;
        }

        $lines = array_map(static fn (array $change): string => $change['kind'] . ' ' . $change['path'], $this->changes($plan));
        $modeValue = $mode->value();
        if ($modeValue === Mode::CHECK) {
            $output->writeln($status === Status::OUT_OF_DATE ? 'Out of date:' : 'Up to date.');
            foreach ($lines as $line) {
                $output->writeln('  ' . $line);
            }

            return;
        }

        if ($modeValue === Mode::DRY_RUN) {
            foreach ($lines as $line) {
                $output->writeln($line);
            }
        }

        $output->writeln(sprintf(
            $modeValue === Mode::DRY_RUN ? 'Would write: %d, delete: %d, unchanged: %d.' : 'Written: %d, deleted: %d, unchanged: %d.',
            $counts[FileChange::CREATE] + $counts[FileChange::UPDATE],
            $counts[FileChange::DELETE],
            $counts[FileChange::UNCHANGED],
        ));
    }

    /**
     * Files that change, then the manifests that change with them.
     *
     * @return list<array{kind: string, path: string}>
     */
    private function changes(?WritePlan $plan): array
    {
        $changes = [];
        foreach ($this->planChanges($plan) as $change) {
            if ($change->isChange()) {
                $changes[] = ['kind' => $change->kind(), 'path' => $this->formatter->path($change->path())];
            }
        }

        if ($plan instanceof WritePlan) {
            foreach (array_keys($plan->manifests()) as $path) {
                $changes[] = ['kind' => $plan->isNewManifest($path) ? FileChange::CREATE : FileChange::UPDATE, 'path' => $this->formatter->path($path)];
            }
        }

        return $changes;
    }

    /**
     * Empty when the run stopped before planning.
     *
     * @return list<FileChange>
     */
    private function planChanges(?WritePlan $plan): array
    {
        return $plan instanceof WritePlan ? $plan->changes() : [];
    }

    /**
     * @return list<array{severity: string, location: string, message: string}>
     */
    private function diagnostics(Output $result): array
    {
        return array_map(fn (Diagnostic $diagnostic): array => [
            'severity' => $diagnostic->severity()->value(),
            'location' => $this->formatter->location($diagnostic->location()),
            'message' => $diagnostic->message(),
        ], $result->diagnostics()->all());
    }

    /**
     * @param list<array{severity: string, location: string, message: string}> $diagnostics
     * @param list<array{kind: string, path: string}> $changes
     */
    private function json(string $status, array $diagnostics, array $changes): string
    {
        return ErrorOutput::encode(['status' => $status, 'diagnostics' => $diagnostics, 'changes' => $changes]);
    }
}
