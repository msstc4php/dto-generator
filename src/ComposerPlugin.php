<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator;

use Closure;
use Composer\Composer;
use Composer\EventDispatcher\EventDispatcher;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Factory;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Composer\Util\ProcessExecutor;
use MSSTC4PHP\DtoGenerator\Presentation\Composer\PluginSettings;
use RuntimeException;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Throwable;

/**
 * Regenerates the DTOs after each autoload dump when the root package sets `extra.dto-generator.config` (spec §9.3).
 * It runs the project's vendor/bin/dto-generator in a process of its own: inside Composer the classes, installed.json
 * and libraries are Composer's, not the project's. Failures only warn unless `failOnError` is true.
 */
final class ComposerPlugin implements PluginInterface, EventSubscriberInterface
{
    private const PREFIX = 'dto-generator: ';

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
        $composer = $event->getComposer();
        $settings = PluginSettings::fromExtra($composer->getPackage()->getExtra());
        if (!$settings instanceof PluginSettings || !$this->runsScripts($composer->getEventDispatcher())) {
            return;
        }

        $io = $event->getIO();
        $problem = $settings->problem();
        if ($problem !== null) {
            $this->fail($io, $settings->failOnError(), $problem);

            return;
        }

        $this->generate($io, $settings->failOnError(), $settings->config(), $composer);
    }

    /**
     * --no-scripts only keeps Composer from running the scripts of composer.json; listeners of plugins still get the
     * event, and the dispatcher has no public getter for the flag. Should a release change that, the plugin runs.
     */
    private function runsScripts(EventDispatcher $dispatcher): bool
    {
        try {
            $read = Closure::bind(static fn (EventDispatcher $dispatcher): bool => $dispatcher->runScripts, null, EventDispatcher::class);

            return $read instanceof Closure ? $read($dispatcher) : true;
        } catch (Throwable $exception) {
            return true;
        }
    }

    /**
     * The CLI runs in the directory of the project's composer.json, which the COMPOSER variable may name, and resolves
     * a relative config path there itself.
     */
    private function generate(IOInterface $io, bool $failOnError, string $config, Composer $composer): void
    {
        $binDir = $composer->getConfig()->get('bin-dir');
        $bin = (is_string($binDir) ? $binDir : 'vendor/bin') . '/dto-generator';
        // PHP_BINARY: the PHP Composer runs on, and Windows does not read the script's shebang.
        $command = implode(' ', array_map([ProcessExecutor::class, 'escape'], [PHP_BINARY, $bin, 'generate', '--config=' . $config, '--no-ansi']));
        $process = new ProcessExecutor($io);
        $output = null;
        // A process that cannot start or outlives Composer's process-timeout throws; that must not break an install either.
        try {
            $exitCode = $process->execute($command, $output, dirname(Factory::getComposerFile()));
        } catch (Throwable $exception) {
            $this->fail($io, $failOnError, 'the generator could not run: ' . $exception->getMessage());

            return;
        }

        $style = $exitCode !== 0 && $failOnError ? 'error' : 'warning';
        foreach ($this->lines($process->getErrorOutput()) as $line) {
            $this->write($io, $style, $line);
        }

        foreach ($this->lines(is_string($output) ? $output : '') as $line) {
            $io->writeError(OutputFormatter::escape(self::PREFIX . $line));
        }

        if ($exitCode !== 0) {
            $this->fail($io, $failOnError, sprintf('the generator exited with code %d.', $exitCode));
        }
    }

    /**
     * @return array<int, string> the lines that are not empty
     */
    private function lines(string $text): array
    {
        $lines = preg_split('~\R~', $text);

        return array_filter(is_array($lines) ? $lines : [], static fn (string $line): bool => $line !== '');
    }

    /**
     * @throws RuntimeException when the command must fail
     */
    private function fail(IOInterface $io, bool $failOnError, string $message): void
    {
        if ($failOnError) {
            throw new RuntimeException(self::PREFIX . $message);
        }

        $this->write($io, 'warning', $message);
    }

    /**
     * Composer formats its output like Symfony Console, which it ships, so the text is escaped, not taken for tags.
     *
     * @param 'error'|'warning' $style
     */
    private function write(IOInterface $io, string $style, string $text): void
    {
        $io->writeError(sprintf('<%1$s>%2$s</%1$s>', $style, OutputFormatter::escape(self::PREFIX . $text)));
    }
}
