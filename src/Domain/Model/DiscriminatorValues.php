<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Model;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;

/**
 * The values of one discriminator that select a final class: its constructor accepts no other.
 */
final class DiscriminatorValues
{
    private string $property;

    /** @var non-empty-list<int|string> */
    private array $values;

    private bool $checked;

    /**
     * @param string $property PHP name of the discriminating property, declared by the class or an ancestor
     * @param list<int|string> $values as the property holds them: the backing values of an enum
     * @param bool $checked false when the property's type admits no other value, so a check could never fail
     */
    public function __construct(string $property, array $values, bool $checked = true)
    {
        if (!Identifier::isValid($property)) {
            throw new InvalidModel(sprintf('"%s" is not a usable PHP property name.', $property));
        }

        if ($values === []) {
            throw new InvalidModel(sprintf('Discriminator property "%s" has no value that selects the class.', $property));
        }

        $this->property = $property;
        $this->values = $values;
        $this->checked = $checked;
    }

    public function property(): string
    {
        return $this->property;
    }

    /**
     * @return non-empty-list<int|string>
     */
    public function values(): array
    {
        return $this->values;
    }

    public function isChecked(): bool
    {
        return $this->checked;
    }
}
