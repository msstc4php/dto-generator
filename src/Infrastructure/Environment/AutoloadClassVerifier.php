<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Infrastructure\Environment;

use Closure;
use Composer\Autoload\ClassLoader;
use ErrorException;
use MSSTC4PHP\DtoGenerator\Application\Port\ClassVerificationFailed;
use MSSTC4PHP\DtoGenerator\Application\Port\ClassVerifier;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use Throwable;

/**
 * Loads the consumer's autoloader into this process on the first question, which runs the consumer's code (spec §9.4).
 * Its Composer loader is moved behind the loaders already registered, so the generator keeps its own dependencies; a
 * package both ship is therefore answered for in the generator's version.
 */
final class AutoloadClassVerifier implements ClassVerifier
{
    private string $autoload;

    private bool $loaded = false;

    private ?string $failure = null;

    public function __construct(string $autoload)
    {
        $this->autoload = $autoload;
    }

    public function hasClass(ClassName $class): bool
    {
        // Enums are classes to class_exists().
        return $this->ask($class->fqcn(), static fn (): bool => class_exists($class->fqcn()));
    }

    public function hasType(ClassName $class): bool
    {
        return $this->ask($class->fqcn(), static fn (): bool => class_exists($class->fqcn()) || interface_exists($class->fqcn()) || trait_exists($class->fqcn()));
    }

    public function hasConstant(?ClassName $class, string $name): bool
    {
        if (!$class instanceof ClassName) {
            $this->load();

            return defined($name);
        }

        // defined() evaluates the constant's expression, which may load more of the consumer's classes or fail.
        $fqcn = $class->fqcn();

        return $this->ask($fqcn . '::' . $name, static fn (): bool => (class_exists($fqcn) || interface_exists($fqcn) || trait_exists($fqcn)) && defined($fqcn . '::' . $name));
    }

    /**
     * @param string $subject the class or constant asked about, for the message
     * @param Closure(): bool $question
     *
     * @throws ClassVerificationFailed
     */
    private function ask(string $subject, Closure $question): bool
    {
        $this->load();

        try {
            return $this->guarded($question);
        } catch (Throwable $exception) {
            throw new ClassVerificationFailed(sprintf('Checking %s failed: %s', $subject, $exception->getMessage()), 0, $exception);
        }
    }

    /**
     * @throws ClassVerificationFailed
     */
    private function load(): void
    {
        if ($this->failure !== null) {
            throw new ClassVerificationFailed($this->failure);
        }

        if ($this->loaded) {
            return;
        }

        $this->loaded = true;
        $registered = ClassLoader::getRegisteredLoaders();
        $autoload = $this->autoload;

        try {
            $loader = $this->guarded(static fn () => require $autoload);
        } catch (Throwable $exception) {
            $this->failure = sprintf('Loading %s failed: %s', $autoload, $exception->getMessage());

            throw new ClassVerificationFailed($this->failure, 0, $exception);
        }

        // Composer prepends its loader; the consumer's must not shadow the generator's own, which is already registered.
        if ($loader instanceof ClassLoader && !in_array($loader, $registered, true)) {
            $loader->unregister();
            $loader->register(false);
        }
    }

    /**
     * Turns E_USER_ERROR, which Composer's platform check raises, into an exception instead of the end of the process,
     * and mutes deprecations, which older consumer code raises on newer PHP and which are no concern of generation.
     *
     * @template T
     *
     * @param Closure(): T $call
     *
     * @return T
     */
    private function guarded(Closure $call)
    {
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if ($severity === E_USER_ERROR) {
                throw new ErrorException($message, 0, $severity, $file, $line);
            }

            return true;
        }, E_USER_ERROR | E_DEPRECATED | E_USER_DEPRECATED);

        try {
            return $call();
        } finally {
            restore_error_handler();
        }
    }
}
