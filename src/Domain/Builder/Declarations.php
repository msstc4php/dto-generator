<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Model\EnumType;

/**
 * What the build generates, by schema location: the class or enum of a named or inline schema, and the schemas
 * excluded by x-php-skip.
 */
final class Declarations
{
    /** @var array<string, ClassName> */
    private array $classes;

    /** @var array<string, EnumType> */
    private array $enums;

    /** @var array<string, true> */
    private array $skipped;

    /** @var array<string, true> */
    private array $abandoned;

    /**
     * @param array<string, ClassName> $classes location key → class
     * @param array<string, EnumType> $enums location key → enum
     * @param array<string, true> $skipped location keys of schemas excluded by x-php-skip
     * @param array<string, true> $abandoned location keys of inline schemas whose declaration failed and was reported
     */
    public function __construct(array $classes = [], array $enums = [], array $skipped = [], array $abandoned = [])
    {
        $this->classes = $classes;
        $this->enums = $enums;
        $this->skipped = $skipped;
        $this->abandoned = $abandoned;
    }

    public function classAt(string $key): ?ClassName
    {
        return $this->classes[$key] ?? null;
    }

    public function enumAt(string $key): ?EnumType
    {
        return $this->enums[$key] ?? null;
    }

    public function isAbandoned(string $key): bool
    {
        return isset($this->abandoned[$key]);
    }

    public function isSkipped(string $key): bool
    {
        return isset($this->skipped[$key]);
    }
}
