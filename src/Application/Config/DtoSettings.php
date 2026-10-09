<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Config;

use MSSTC4PHP\DtoGenerator\Domain\Target\AccessorStyle;
use MSSTC4PHP\DtoGenerator\Domain\Target\AllOfStrategy;
use MSSTC4PHP\DtoGenerator\Domain\Target\DateTimeClass;
use MSSTC4PHP\DtoGenerator\Domain\Target\Mutability;

final class DtoSettings
{
    private Mutability $mutability;

    private AccessorStyle $accessors;

    private DateTimeClass $dateTimeClass;

    private AllOfStrategy $allOfStrategy;

    private bool $withers;

    public function __construct(Mutability $mutability, AccessorStyle $accessors, DateTimeClass $dateTimeClass, AllOfStrategy $allOfStrategy, bool $withers = true)
    {
        $this->mutability = $mutability;
        $this->accessors = $accessors;
        $this->dateTimeClass = $dateTimeClass;
        $this->allOfStrategy = $allOfStrategy;
        $this->withers = $withers;
    }

    public function mutability(): Mutability
    {
        return $this->mutability;
    }

    public function accessors(): AccessorStyle
    {
        return $this->accessors;
    }

    public function dateTimeClass(): DateTimeClass
    {
        return $this->dateTimeClass;
    }

    public function allOfStrategy(): AllOfStrategy
    {
        return $this->allOfStrategy;
    }

    public function hasWithers(): bool
    {
        return $this->withers;
    }
}
