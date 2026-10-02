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
        $this->workingDirectory = $workingDirectory;
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
            return $this->usageError($output, '--format must be "text" or "json".');
        }

        $check = $options['check'] === true;
        $dryRun = $options['dry-run'] === true;
        if ($check && $dryRun) {
            return $this->usageError($output, '--check and --dry-run cannot be combined.');
        }

        $config = $this->configPath($options['config'] ?? null);
        if ($config === null) {
            return $this->usageError($output, 'No dto-generator.yaml or dto-generator.json here; pass --config.');
        }

        $mode = Mode::from($check ? Mode::CHECK : ($dryRun ? Mode::DRY_RUN : Mode::WRITE));
        $result = ($this->generate)(new Input($config, $mode));
        if ($format === 'json') {
            $output->writeln($this->json($result));
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

    private function usageError(OutputInterface $output, string $message): int
    {
        $output->writeln('error: ' . $message);

        return self::EXIT_CODES[Status::CONFIG_FAILED];
    }

    private function text(Output $result, Mode $mode, OutputInterface $output): void
    {
        foreach ($result->diagnostics()->all() as $diagnostic) {
            $output->writeln($this->formatter->line($diagnostic));
        }

        $errors = count($result->diagnostics()->errors());
        $status = $result->status()->value();
        if ($status === Status::CONFIG_FAILED) {
            $output->writeln(sprintf('Configuration failed: %d error(s).', $errors));

            return;
        }

        if ($status === Status::GENERATION_FAILED) {
            $output->writeln(sprintf('Generation failed: %d error(s).', $errors));

            return;
        }

        $changes = $this->changes($result);
        $counts = [FileChange::CREATE => 0, FileChange::UPDATE => 0, FileChange::DELETE => 0, FileChange::UNCHANGED => 0];
        foreach ($changes as $change) {
            $counts[$change->kind()]++;
        }

        $modeValue = $mode->value();
        if ($modeValue === Mode::CHECK) {
            $output->writeln($status === Status::OUT_OF_DATE ? 'Out of date:' : 'Up to date.');
            foreach ($this->changeLines($changes) as $line) {
                $output->writeln('  ' . $line);
            }

            return;
        }

        if ($modeValue === Mode::DRY_RUN) {
            foreach ($this->changeLines($changes) as $line) {
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
     * @param list<FileChange> $changes
     *
     * @return list<string>
     */
    private function changeLines(array $changes): array
    {
        $lines = [];
        foreach ($changes as $change) {
            if ($change->isChange()) {
                $lines[] = $change->kind() . ' ' . $this->formatter->path($change->path());
            }
        }

        return $lines;
    }

    private function json(Output $result): string
    {
        $changes = [];
        foreach ($this->changes($result) as $change) {
            if ($change->isChange()) {
                $changes[] = ['kind' => $change->kind(), 'path' => $this->formatter->path($change->path())];
            }
        }

        return (string) json_encode(
            [
                'status' => $result->status()->value(),
                'diagnostics' => array_map(fn (Diagnostic $diagnostic): array => [
                    'severity' => $diagnostic->severity()->value(),
                    'location' => $this->formatter->location($diagnostic->location()),
                    'message' => $diagnostic->message(),
                ], $result->diagnostics()->all()),
                'changes' => $changes,
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    /**
     * @return list<FileChange>
     */
    private function changes(Output $result): array
    {
        $plan = $result->plan();

        return $plan instanceof WritePlan ? $plan->changes() : [];
    }
}
