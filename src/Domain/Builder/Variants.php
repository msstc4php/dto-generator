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

    /**
     * @param list<ClassName> $classes
     */
    public function __construct(array $classes, ?DiscriminatorModel $discriminator)
    {
        $this->classes = $classes;
        $this->discriminator = $discriminator;
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
}
