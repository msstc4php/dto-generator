<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Target;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;

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

    private function __construct(
        bool $promoted,
        bool $publicProperties,
        bool $readonlyProperties,
        bool $readonlyClass,
        bool $setters,
        WitherStyle $withers
    ) {
        $this->promoted = $promoted;
        $this->publicProperties = $publicProperties;
        $this->readonlyProperties = $readonlyProperties;
        $this->readonlyClass = $readonlyClass;
        $this->getters = !$publicProperties;
        $this->setters = $setters;
        $this->withers = $withers;
    }

    /**
     * Private properties get getters and setters; public ones are changed directly.
     */
    public static function mutable(bool $promoted, bool $publicProperties): self
    {
        return new self($promoted, $publicProperties, false, false, !$publicProperties, WitherStyle::from(WitherStyle::NONE));
    }

    /**
     * Private properties get getters; every property gets a wither.
     */
    public static function immutable(
        bool $promoted,
        bool $publicProperties,
        ReadonlyMode $readonly,
        WitherStyle $withers
    ): self {
        $none = $readonly->equals(ReadonlyMode::from(ReadonlyMode::NONE));
        // Untyped 7.4-style declarations cannot be readonly, and every target with readonly also promotes.
        if (!$none && !$promoted) {
            throw new InvalidModel('Readonly properties and classes need promoted properties.');
        }

        if (!$none && $withers->equals(WitherStyle::from(WitherStyle::CLONE_ASSIGN))) {
            throw new InvalidModel('A wither cannot assign to a readonly clone before PHP 8.5; use new self or clone with.');
        }

        if ($withers->isNone()) {
            throw new InvalidModel('An immutable class needs withers to produce modified copies.');
        }

        return new self(
            $promoted,
            $publicProperties,
            $readonly->equals(ReadonlyMode::from(ReadonlyMode::PROPERTIES)),
            $readonly->equals(ReadonlyMode::from(ReadonlyMode::CLASS_)),
            false,
            $withers,
        );
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
