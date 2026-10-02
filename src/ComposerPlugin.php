<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator;

use Closure;
use Composer\Composer;
use Composer\EventDispatcher\EventDispatcher;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use MSSTC4PHP\DtoGenerator\Application\Service\Generate\FileChange;
use MSSTC4PHP\DtoGenerator\Application\Service\Generate\Input;
use MSSTC4PHP\DtoGenerator\Application\Service\Generate\Mode;
use MSSTC4PHP\DtoGenerator\Application\Service\Generate\Output;
use MSSTC4PHP\DtoGenerator\Application\Service\Generate\Status;
use MSSTC4PHP\DtoGenerator\Application\Service\Generate\WritePlan;
use MSSTC4PHP\DtoGenerator\Presentation\Cli\DiagnosticFormatter;
use RuntimeException;
use Throwable;

/**
 * Regenerates the DTOs after each autoload dump when the root package sets `extra.dto-generator.config` (spec §9.3).
 * Failures only warn unless `extra.dto-generator.failOnError` is true, so an install never breaks by default.
 * Composer itself skips the plugin with --no-plugins; --no-scripts the plugin has to honour itself.
 */
final class ComposerPlugin implements PluginInterface, EventSubscriberInterface
{
    private const PREFIX = 'dto-generator: ';

    /** @var Closure(Input): Output */
    private Closure $generator;

    /**
     * @param (Closure(Input): Output)|null $generator the generator to run; Composer creates the plugin without one
     */
    public function __construct(?Closure $generator = null)
    {
        $this->generator = $generator ?? static fn (Input $input): Output => DtoGenerator::generator()($input);
    }

    public function activate(Composer $composer, IOInterface $io): void
    {
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [ScriptEvents::POST_AUTOLOAD_DUMP => 'onPostAutoloadDump'];
    }

    public function onPostAutoloadDump(Event $event): void
    {
        $extra = $event->getComposer()->getPackage()->getExtra();
        if (!array_key_exists('dto-generator', $extra) || !$this->runsScripts($event->getComposer()->getEventDispatcher())) {
            return;
        }

        $io = $event->getIO();
        $settings = $extra['dto-generator'];
        if (!is_array($settings)) {
            $this->fail($io, false, 'extra.dto-generator must be an object with "config", the path of the config file.');

            return;
        }

        $failOnError = $settings['failOnError'] ?? false;
        if (!is_bool($failOnError)) {
            $this->fail($io, false, 'extra.dto-generator.failOnError must be true or false.');

            return;
        }

        if (!array_key_exists('config', $settings)) {
            return;
        }

        $config = $settings['config'];
        if (!is_string($config) || $config === '') {
            $this->fail($io, $failOnError, 'extra.dto-generator.config must be the path of the config file.');

            return;
        }

        $this->generate($io, $failOnError, $config);
    }

    /**
     * --no-scripts only keeps Composer from running the scripts of composer.json; listeners of plugins still get the
     * event, and the dispatcher has no public getter for the flag (a protected property in every Composer 2).
     */
    private function runsScripts(EventDispatcher $dispatcher): bool
    {
        $read = Closure::bind(static fn (EventDispatcher $dispatcher): bool => $dispatcher->runScripts, null, EventDispatcher::class);

        return $read instanceof Closure && $read($dispatcher);
    }

    private function generate(IOInterface $io, bool $failOnError, string $config): void
    {
        // Composer runs in the project's directory, --working-dir included.
        $workingDirectory = (string) getcwd();
        $path = preg_match('~^([a-zA-Z]:)?[/\\\\]~', $config) === 1 ? $config : $workingDirectory . '/' . $config;

        try {
            $result = ($this->generator)(new Input($path, Mode::from(Mode::WRITE)));
        } catch (Throwable $exception) {
            $this->fail($io, $failOnError, sprintf('generation stopped: %s', $exception->getMessage()));

            return;
        }

        $formatter = new DiagnosticFormatter($workingDirectory);
        $failed = $this->failed($result);
        foreach ($result->diagnostics()->all() as $diagnostic) {
            $line = self::PREFIX . $formatter->line($diagnostic);
            $this->write($io, $diagnostic->severity()->isError() && $failOnError ? 'error' : 'warning', $line);
        }

        if ($failed !== null) {
            $this->fail($io, $failOnError, $failed);

            return;
        }

        $io->writeError(self::PREFIX . $this->summary($result->plan()));
    }

    private function failed(Output $result): ?string
    {
        $status = $result->status()->value();
        if ($status !== Status::CONFIG_FAILED && $status !== Status::GENERATION_FAILED) {
            return null;
        }

        return sprintf(
            '%s failed with %d error(s); nothing was written.',
            $status === Status::CONFIG_FAILED ? 'configuration' : 'generation',
            count($result->diagnostics()->errors()),
        );
    }

    private function summary(?WritePlan $plan): string
    {
        $counts = [FileChange::CREATE => 0, FileChange::UPDATE => 0, FileChange::DELETE => 0, FileChange::UNCHANGED => 0];
        foreach ($plan instanceof WritePlan ? $plan->changes() : [] as $change) {
            $counts[$change->kind()]++;
        }

        return sprintf(
            'written %d, deleted %d, unchanged %d.',
            $counts[FileChange::CREATE] + $counts[FileChange::UPDATE],
            $counts[FileChange::DELETE],
            $counts[FileChange::UNCHANGED],
        );
    }

    /**
     * @throws RuntimeException when the command must fail
     */
    private function fail(IOInterface $io, bool $failOnError, string $message): void
    {
        if ($failOnError) {
            throw new RuntimeException(self::PREFIX . $message);
        }

        $this->write($io, 'warning', self::PREFIX . $message);
    }

    /**
     * Composer formats its output like Symfony Console, so a "<" of the text is escaped, not taken for a tag.
     *
     * @param 'error'|'warning' $style
     */
    private function write(IOInterface $io, string $style, string $text): void
    {
        $io->writeError(sprintf('<%1$s>%2$s</%1$s>', $style, addcslashes($text, '<')));
    }
}
