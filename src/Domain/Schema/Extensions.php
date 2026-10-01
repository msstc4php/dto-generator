<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Schema;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;

/**
 * @phpstan-import-type JsonValue from Json
 */
final class Extensions
{
    /** @var array<string, JsonValue> */
    private array $values;

    /**
     * @param array<string, JsonValue> $values
     */
    public function __construct(array $values = [])
    {
        foreach (array_keys($values) as $key) {
            if (strncmp($key, 'x-', 2) !== 0 || strlen($key) === 2) {
                throw new InvalidModel(sprintf('Extension key "%s" must start with "x-" followed by a name.', $key));
            }
        }

        $this->values = $values;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    /**
     * @return JsonValue
     */
    public function get(string $key)
    {
        if (!$this->has($key)) {
            throw new InvalidModel(sprintf('Extension "%s" is not set.', $key));
        }

        return $this->values[$key];
    }

    /**
     * @return array<string, JsonValue>
     */
    public function all(): array
    {
        return $this->values;
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->values);
    }

    public function isEmpty(): bool
    {
        return $this->values === [];
    }
}
