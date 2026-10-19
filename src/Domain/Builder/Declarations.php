<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Model\EnumType;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;

/**
 * What the build generates, by schema location: the class or enum of a named or inline schema, and the schemas
 * excluded by x-php-skip.
 */
final class Declarations
{
    /** @var array<string, ClassName> */
    private array $classes;

    /** @var array<string, ClassName> by FQCN */
    private array $generated = [];

    /** @var array<string, EnumType> */
    private array $enums;

    /** @var array<string, true> */
    private array $skipped;

    /** @var array<string, true> */
    private array $abandoned;

    /** @var array<string, Schema> by FQCN */
    private array $schemas;

    /** @var array<string, array<string, string>> FQCN → FQCNs, by themselves */
    private array $wider = [];

    /**
     * @param array<string, ClassName> $classes location key → class
     * @param array<string, EnumType> $enums location key → enum
     * @param array<string, true> $skipped location keys of schemas excluded by x-php-skip
     * @param array<string, true> $abandoned location keys of inline schemas whose declaration failed and was reported
     * @param array<string, Schema> $schemas FQCN → the one schema a class is built from, as written
     */
    public function __construct(array $classes = [], array $enums = [], array $skipped = [], array $abandoned = [], array $schemas = [])
    {
        $this->classes = $classes;
        foreach ($classes as $class) {
            $this->generated[$class->fqcn()] = $class;
        }
        $this->enums = $enums;
        $this->skipped = $skipped;
        $this->abandoned = $abandoned;
        $this->schemas = $schemas;
    }

    /**
     * Whether the build generates the class, as opposed to a class a format or x-php-type names.
     */
    public function generates(ClassName $class): bool
    {
        return isset($this->generated[$class->fqcn()]);
    }

    /**
     * The schema a generated class is built from; null when the class takes its properties from several or leaves some
     * out.
     */
    public function schemaOf(ClassName $class): ?Schema
    {
        return $this->schemas[$class->fqcn()] ?? null;
    }

    /**
     * @param array<string, array<string, string>> $wider FQCN → the FQCNs of the classes that admit every object of the
     *                                                    class, keyed by themselves
     */
    public function withWider(array $wider): self
    {
        $declarations = clone $this;
        $declarations->wider = $wider;

        return $declarations;
    }

    /**
     * Whether every object of a generated class is one of the other: it extends the other, or its `allOf` has the other,
     * which restricts its objects only by the properties the class merges.
     */
    public function isWider(ClassName $wide, ClassName $narrow): bool
    {
        return isset($this->wider[$narrow->fqcn()][$wide->fqcn()]);
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
