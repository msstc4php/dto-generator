<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Target;

use MSSTC4PHP\DtoGenerator\Domain\Exception\UnsupportedPhpVersion;

/**
 * @api
 */
final class PhpVersion
{
    private const SUPPORTED = ['7.4', '8.0', '8.1', '8.2', '8.3', '8.4', '8.5'];

    private int $major;

    private int $minor;

    private function __construct(int $major, int $minor)
    {
        $this->major = $major;
        $this->minor = $minor;
    }

    public static function fromString(string $version): self
    {
        if (preg_match('/^(?<major>0|[1-9]\d*)\.(?<minor>0|[1-9]\d*)(?:\.(?:0|[1-9]\d*))?\z/', $version, $matches) !== 1) {
            throw UnsupportedPhpVersion::malformed($version);
        }

        if (!in_array($matches['major'] . '.' . $matches['minor'], self::SUPPORTED, true)) {
            throw UnsupportedPhpVersion::notSupported($version, self::SUPPORTED);
        }

        return new self((int) $matches['major'], (int) $matches['minor']);
    }

    public static function oldest(): self
    {
        return self::supported()[0];
    }

    public static function newest(): self
    {
        $supported = self::supported();

        return $supported[count($supported) - 1];
    }

    /**
     * @return non-empty-list<self>
     */
    public static function supported(): array
    {
        return array_map(static fn (string $version): self => self::fromString($version), self::SUPPORTED);
    }

    public function major(): int
    {
        return $this->major;
    }

    public function minor(): int
    {
        return $this->minor;
    }

    /**
     * Same encoding as PHP_VERSION_ID with the patch part zeroed.
     */
    public function id(): int
    {
        return $this->major * 10000 + $this->minor * 100;
    }

    public function isAtLeast(self $other): bool
    {
        return $this->id() >= $other->id();
    }

    public function equals(self $other): bool
    {
        return $this->id() === $other->id();
    }

    public function toString(): string
    {
        return $this->major . '.' . $this->minor;
    }
}
