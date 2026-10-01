<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Exception;

use InvalidArgumentException;

final class UnsupportedPhpVersion extends InvalidArgumentException implements DomainError
{
    public static function malformed(string $version): self
    {
        return new self(sprintf('"%s" is not a PHP version; expected "<major>.<minor>" such as "8.2".', $version));
    }

    /**
     * @param list<string> $supported
     */
    public static function notSupported(string $version, array $supported): self
    {
        return new self(sprintf('PHP %s is not supported; supported versions: %s.', $version, implode(', ', $supported)));
    }
}
