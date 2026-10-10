<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Config;

use MSSTC4PHP\DtoGenerator\Domain\Target\AccessorStyle;
use MSSTC4PHP\DtoGenerator\Domain\Target\AllOfStrategy;
use MSSTC4PHP\DtoGenerator\Domain\Target\DateTimeClass;
use MSSTC4PHP\DtoGenerator\Domain\Target\Mutability;
use MSSTC4PHP\DtoGenerator\Domain\Target\ReadWriteModels;

final class DtoSettings
{
    private Mutability $mutability;

    private AccessorStyle $accessors;

    private DateTimeClass $dateTimeClass;

    private AllOfStrategy $allOfStrategy;

    private bool $withers;

    private ReadWriteModels $readWriteModels;

    private ViewSuffixes $viewSuffixes;

    public function __construct(
        Mutability $mutability,
        AccessorStyle $accessors,
        DateTimeClass $dateTimeClass,
        AllOfStrategy $allOfStrategy,
        bool $withers = true,
        ?ReadWriteModels $readWriteModels = null,
        ?ViewSuffixes $viewSuffixes = null
    ) {
        $this->mutability = $mutability;
        $this->accessors = $accessors;
        $this->dateTimeClass = $dateTimeClass;
        $this->allOfStrategy = $allOfStrategy;
        $this->withers = $withers;
        $this->readWriteModels = $readWriteModels ?? ReadWriteModels::from(ReadWriteModels::SINGLE);
        $this->viewSuffixes = $viewSuffixes ?? new ViewSuffixes();
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

    /**
     * Whether a class whose model depends on readOnly/writeOnly gets a read and a write view (`readWriteModels: split`).
     */
    public function splitsReadAndWrite(): bool
    {
        return $this->readWriteModels->isSplit();
    }

    public function readWriteModels(): ReadWriteModels
    {
        return $this->readWriteModels;
    }

    public function viewSuffixes(): ViewSuffixes
    {
        return $this->viewSuffixes;
    }
}
