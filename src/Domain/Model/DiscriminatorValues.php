<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Model;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;

/**
 * The values of one discriminator a concrete class accepts: those that select it or one of its subclasses.
 *
 * @api
 */
final class DiscriminatorValues
{
    private string $property;

    /** @var non-empty-list<int|string> */
    private array $values;

    private bool $checked;

    /** @var non-empty-list<int|string> */
    private array $own;

    /** @var list<int|string> */
    private array $subclassValues;

    private bool $subclassChecked;

    /**
     * @param string $property PHP name of the discriminating property, declared by the class or an ancestor
     * @param list<int|string> $values as the property holds them: the backing values of an enum
     * @param bool $checked false when the property's type admits no value but the class's own, so a check could never fail
     * @param list<int|string> $subclassValues those of $values only a subclass passes up: an open class itself refuses them
     * @param bool $subclassChecked false when the type admits no value beyond $values
     */
    public function __construct(string $property, array $values, bool $checked = true, array $subclassValues = [], bool $subclassChecked = true)
    {
        if (!Identifier::isValid($property)) {
            throw new InvalidModel(sprintf('"%s" is not a usable PHP property name.', $property));
        }

        foreach ($subclassValues as $value) {
            if (!in_array($value, $values, true)) {
                throw new InvalidModel(sprintf('Subclass value "%s" of discriminator property "%s" is not one of its values.', $value, $property));
            }
        }

        $own = [];
        foreach ($values as $value) {
            if (!in_array($value, $subclassValues, true)) {
                $own[] = $value;
            }
        }

        if ($values === [] || $own === []) {
            throw new InvalidModel(sprintf('Discriminator property "%s" has no value that selects the class.', $property));
        }

        $this->property = $property;
        $this->values = $values;
        $this->checked = $checked;
        $this->own = $own;
        $this->subclassValues = $subclassValues;
        $this->subclassChecked = $subclassChecked;
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

    /**
     * @return non-empty-list<int|string> the values that select the class itself
     */
    public function ownValues(): array
    {
        return $this->own;
    }

    /**
     * @return list<int|string>
     */
    public function subclassValues(): array
    {
        return $this->subclassValues;
    }

    public function isChecked(): bool
    {
        return $this->checked;
    }

    public function areSubclassValuesChecked(): bool
    {
        return $this->subclassChecked;
    }
}
