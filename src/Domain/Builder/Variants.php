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

    private bool $listed;

    /**
     * @param list<ClassName> $classes
     * @param bool $listed whether a `oneOf`/`anyOf` lists the variants, rather than they extend the base through `allOf`
     */
    public function __construct(array $classes, ?DiscriminatorModel $discriminator, bool $listed)
    {
        $this->classes = $classes;
        $this->discriminator = $discriminator;
        $this->listed = $listed;
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

    /**
     * Listed variants are self-contained: the base adopts them and takes what they all declare alike. Subclasses found
     * through `allOf` already extend the base and declare only their own properties.
     */
    public function isListed(): bool
    {
        return $this->listed;
    }
}
