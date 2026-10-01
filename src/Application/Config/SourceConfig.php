<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Config;

use InvalidArgumentException;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Path;

final class SourceConfig
{
    private string $spec;

    private string $namespace;

    private string $outputDir;

    /** @var non-empty-list<string> */
    private array $include;

    /** @var list<string> */
    private array $exclude;

    /**
     * @param list<string> $include glob patterns over component schema names
     * @param list<string> $exclude glob patterns over component schema names
     */
    public function __construct(string $spec, string $namespace, string $outputDir, array $include, array $exclude)
    {
        if (!Path::isAbsolute($spec) || !Path::isAbsolute($outputDir)) {
            throw new InvalidArgumentException('Source paths must be absolute.');
        }

        if ($include === []) {
            throw new InvalidArgumentException('A source must include at least one pattern.');
        }

        $this->spec = Path::normalize($spec);
        $this->namespace = $namespace;
        $this->outputDir = Path::normalize($outputDir);
        $this->include = $include;
        $this->exclude = $exclude;
    }

    public function spec(): string
    {
        return $this->spec;
    }

    public function namespace(): string
    {
        return $this->namespace;
    }

    public function outputDir(): string
    {
        return $this->outputDir;
    }

    /**
     * @return non-empty-list<string>
     */
    public function include(): array
    {
        return $this->include;
    }

    /**
     * @return list<string>
     */
    public function exclude(): array
    {
        return $this->exclude;
    }

    /**
     * Whether a component schema name passes the include and exclude globs.
     */
    public function selects(string $name): bool
    {
        $matches = static fn (string $pattern): bool => fnmatch($pattern, $name);

        return array_filter($this->include, $matches) !== [] && array_filter($this->exclude, $matches) === [];
    }
}
