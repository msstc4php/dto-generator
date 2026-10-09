<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Shared;

use LogicException;
use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;

/**
 * Stand-in for native enums, which need PHP 8.1 while the generator runs on 7.4.
 * Instances are interned per class and value, so `===` is value equality.
 *
 * @api
 */
abstract class AbstractEnum
{
    /** @var array<class-string<self>, array<string, self>> */
    private static array $instances = [];

    private string $value;

    final protected function __construct(string $value)
    {
        $this->value = $value;
    }

    private function __clone()
    {
    }

    /**
     * @return never
     */
    public function __sleep(): array
    {
        throw new LogicException(sprintf('%s is interned and cannot be serialized.', static::class));
    }

    public function __wakeup(): void
    {
        throw new LogicException(sprintf('%s is interned and cannot be unserialized.', static::class));
    }

    /**
     * @return static
     */
    public static function from(string $value): self
    {
        $instance = static::tryFrom($value);
        if (!$instance instanceof AbstractEnum) {
            throw new InvalidModel(sprintf('"%s" is not a valid %s value; expected one of: %s.', $value, static::class, implode(', ', static::values())));
        }

        return $instance;
    }

    /**
     * @return static|null
     */
    public static function tryFrom(string $value): ?self
    {
        if (!in_array($value, static::values(), true)) {
            return null;
        }

        self::$instances[static::class][$value] ??= new static($value);

        $instance = self::$instances[static::class][$value];
        if (!$instance instanceof static) {
            throw new LogicException(sprintf('Enum cache for %s holds a foreign instance.', static::class));
        }

        return $instance;
    }

    /**
     * @return list<static>
     */
    public static function cases(): array
    {
        $cases = [];
        foreach (static::values() as $value) {
            $cases[] = static::from($value);
        }

        return $cases;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this === $other;
    }

    /**
     * @return non-empty-list<non-empty-string>
     */
    abstract protected static function values(): array;
}
