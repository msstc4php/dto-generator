<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Model\DiscriminatorModel;

/**
 * The classes a discriminated base stands for, and how the discriminator tells them apart.
 */
final class Variants
{
    /** @var list<ClassName> */
    private array $classes;

    private ?DiscriminatorModel $discriminator;

    private bool $sharesProperties;

    /**
     * @param list<ClassName> $classes
     * @param bool $sharesProperties whether properties all variants declare alike move to the base: a `oneOf` lists
     *                               self-contained variants, an `allOf` subclass already declares only its own
     */
    public function __construct(array $classes, ?DiscriminatorModel $discriminator, bool $sharesProperties)
    {
        $this->classes = $classes;
        $this->discriminator = $discriminator;
        $this->sharesProperties = $sharesProperties;
    }

    /**
     * @return list<ClassName>
     */
    public function classes(): array
    {
        return $this->classes;
    }

    public function discriminator(): ?DiscriminatorModel
    {
        return $this->discriminator;
    }

    public function sharesProperties(): bool
    {
        return $this->sharesProperties;
    }
}
