<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Config;

use InvalidArgumentException;

/**
 * Which operations of a source give classes for their request bodies and responses: glob patterns over the
 * operationId, `<method> <path>` (`get /pets/{id}`) when it has none, or the name of a request body or response of
 * `components`.
 */
final class OperationFilter
{
    /** @var non-empty-list<string> */
    private array $include;

    /** @var list<string> */
    private array $exclude;

    /**
     * @param array<int, string> $include
     * @param array<int, string> $exclude
     */
    public function __construct(array $include, array $exclude)
    {
        if ($include === []) {
            throw new InvalidArgumentException('An operation filter must include at least one pattern.');
        }

        $this->include = array_values($include);
        $this->exclude = array_values($exclude);
    }

    public static function all(): self
    {
        return new self(['*'], []);
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

    public function selects(string $name): bool
    {
        $matches = static fn (string $pattern): bool => fnmatch($pattern, $name);

        return array_filter($this->include, $matches) !== [] && array_filter($this->exclude, $matches) === [];
    }
}
