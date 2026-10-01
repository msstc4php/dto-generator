<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Target;

/**
 * The shape of one generated class, resolved from the target and the class mutability (spec §6.2).
 */
final class ClassForm
{
    private bool $promoted;

    private bool $publicProperties;

    private bool $readonlyProperties;

    private bool $readonlyClass;

    private bool $getters;

    private bool $setters;

    private WitherStyle $withers;

    public function __construct(
        bool $promoted,
        bool $publicProperties,
        bool $readonlyProperties,
        bool $readonlyClass,
        bool $getters,
        bool $setters,
        WitherStyle $withers
    ) {
        $this->promoted = $promoted;
        $this->publicProperties = $publicProperties;
        $this->readonlyProperties = $readonlyProperties;
        $this->readonlyClass = $readonlyClass;
        $this->getters = $getters;
        $this->setters = $setters;
        $this->withers = $withers;
    }

    public function isPromoted(): bool
    {
        return $this->promoted;
    }

    public function hasPublicProperties(): bool
    {
        return $this->publicProperties;
    }

    public function hasReadonlyProperties(): bool
    {
        return $this->readonlyProperties;
    }

    public function isReadonlyClass(): bool
    {
        return $this->readonlyClass;
    }

    public function hasGetters(): bool
    {
        return $this->getters;
    }

    public function hasSetters(): bool
    {
        return $this->setters;
    }

    public function withers(): WitherStyle
    {
        return $this->withers;
    }
}
