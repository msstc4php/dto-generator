<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Config;

use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;

/**
 * Typed, located reads from one decoded config object. A wrong value becomes a diagnostic and the default
 * is used, so every mistake in the file is reported in one run.
 *
 * @phpstan-import-type JsonValue from Json
 */
final class RawSection
{
    /** @var array<array-key, mixed> */
    private array $values;

    private SchemaLocation $location;

    private Diagnostics $diagnostics;

    /**
     * @param array<array-key, mixed> $values
     */
    public function __construct(array $values, SchemaLocation $location, Diagnostics $diagnostics)
    {
        $this->values = $values;
        $this->location = $location;
        $this->diagnostics = $diagnostics;
    }

    public function location(): SchemaLocation
    {
        return $this->location;
    }

    /**
     * @param list<string> $known
     */
    public function rejectUnknownKeys(array $known): void
    {
        foreach ($this->keys() as $key) {
            if (!in_array($key, $known, true)) {
                $this->report(sprintf('Unknown key "%s"; expected one of: %s.', $key, implode(', ', $known)), $key);
            }
        }
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    /**
     * @return JsonValue
     */
    public function raw(string $key)
    {
        return $this->has($key) ? Json::value($this->values[$key]) : null;
    }

    public function requiredString(string $key): ?string
    {
        if (!$this->has($key)) {
            $this->report(sprintf('"%s" is required.', $key));

            return null;
        }

        $value = $this->values[$key];
        if (!is_string($value) || $value === '') {
            $this->error($key, 'must be a non-empty string');

            return null;
        }

        return $value;
    }

    /**
     * @param list<string> $allowed
     */
    public function choice(string $key, string $default, array $allowed): string
    {
        if (!$this->has($key)) {
            return $default;
        }

        $value = $this->values[$key];
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            $this->error($key, sprintf('must be one of: %s', implode(', ', $allowed)));

            return $default;
        }

        return $value;
    }

    public function bool(string $key, bool $default): bool
    {
        if (!$this->has($key)) {
            return $default;
        }

        $value = $this->values[$key];
        if (!is_bool($value)) {
            $this->error($key, 'must be true or false');

            return $default;
        }

        return $value;
    }

    /**
     * @param list<string> $default
     *
     * @return list<string>
     */
    public function stringList(string $key, array $default): array
    {
        if (!$this->has($key)) {
            return $default;
        }

        $value = $this->values[$key];
        if (!is_array($value) || !Json::isList($value)) {
            $this->error($key, 'must be a list of strings');

            return $default;
        }

        $strings = [];
        foreach ($value as $index => $item) {
            if (!is_string($item) || $item === '') {
                $this->report('Expected a non-empty string.', $key, (string) $index);

                continue;
            }

            $strings[] = $item;
        }

        return $strings;
    }

    public function section(string $key): self
    {
        $value = $this->has($key) ? $this->values[$key] : [];
        if (!is_array($value) || ($value !== [] && Json::isList($value))) {
            $this->error($key, 'must be an object');
            $value = [];
        }

        return new self($value, $this->location->child($key), $this->diagnostics);
    }

    /**
     * @return list<self>
     */
    public function sectionList(string $key): array
    {
        if (!$this->has($key)) {
            $this->report(sprintf('"%s" is required.', $key));

            return [];
        }

        $value = $this->values[$key];
        if (!is_array($value) || $value === [] || !Json::isList($value)) {
            $this->error($key, 'must be a non-empty list');

            return [];
        }

        $sections = [];
        foreach ($value as $index => $item) {
            if (!is_array($item) || ($item !== [] && Json::isList($item))) {
                $this->report('Expected an object.', $key, (string) $index);

                continue;
            }

            $sections[] = new self($item, $this->location->child($key, (string) $index), $this->diagnostics);
        }

        return $sections;
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_map('strval', array_keys($this->values));
    }

    public function error(string $key, string $problem): void
    {
        $this->report(sprintf('"%s" %s.', $key, $problem), $key);
    }

    public function report(string $message, string ...$path): void
    {
        $this->diagnostics->error($message, $this->location->child(...$path));
    }
}
