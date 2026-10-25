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

    /** @var list<string> */
    private array $required;

    /**
     * @param list<ClassName> $classes
     * @param bool $listed whether a `oneOf`/`anyOf` lists the variants, rather than they extend the base through `allOf`
     * @param list<string> $required what the `required` of a base that lists its variants names
     */
    public function __construct(array $classes, ?DiscriminatorModel $discriminator, bool $listed, array $required = [])
    {
        $this->classes = $classes;
        $this->discriminator = $discriminator;
        $this->listed = $listed;
        $this->required = $required;
    }

    /**
     * What the base requires of every value: a variant that extends it through `allOf` requires it too, one that is
     * only listed requires what its own schema does.
     *
     * @return list<string>
     */
    public function required(): array
    {
        return $this->required;
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
