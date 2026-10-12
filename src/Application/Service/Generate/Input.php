<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Service\Generate;

use InvalidArgumentException;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Path;

/**
 * @api
 */
final class Input
{
    private string $configPath;

    private Mode $mode;

    private ?string $outputRoot = null;

    private bool $fetches = true;

    private bool $refreshes = false;

    public function __construct(string $configPath, Mode $mode)
    {
        $this->configPath = $configPath;
        $this->mode = $mode;
    }

    public function configPath(): string
    {
        return $this->configPath;
    }

    public function mode(): Mode
    {
        return $this->mode;
    }

    /**
     * Writes every source's output directory under $directory instead, at its path relative to the config's directory,
     * such as into a build directory that the project's sources never see.
     *
     * @throws InvalidArgumentException when $directory is not absolute
     */
    public function withOutputRoot(string $directory): self
    {
        if (!Path::isAbsolute($directory)) {
            throw new InvalidArgumentException(sprintf('The output root "%s" must be an absolute path.', $directory));
        }

        $input = clone $this;
        $input->outputRoot = Path::normalize($directory);

        return $input;
    }

    public function outputRoot(): ?string
    {
        return $this->outputRoot;
    }

    /**
     * Remote documents come from the cache only, also when the run writes: a build must not depend on the network.
     */
    public function withoutFetching(): self
    {
        $input = clone $this;
        $input->fetches = false;

        return $input;
    }

    /**
     * Only a run that writes fetches remote documents into the cache; checks read what the cache holds.
     */
    public function fetchesRemote(): bool
    {
        return $this->fetches && $this->mode->value() === Mode::WRITE;
    }

    /**
     * Fetches every remote document the run reads again, replacing what the cache holds. Only a run that fetches can
     * refresh: in a check, a dry run or after withoutFetching() this changes nothing.
     */
    public function withRefreshedRemote(): self
    {
        $input = clone $this;
        $input->refreshes = true;

        return $input;
    }

    /**
     * Whether a refresh was asked for and the run fetches.
     */
    public function refreshesRemote(): bool
    {
        return $this->refreshes && $this->fetchesRemote();
    }
}
