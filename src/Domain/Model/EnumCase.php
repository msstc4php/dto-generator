<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Model;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;

final class EnumCase
{
    private string $name;

    /** @var int|string */
    private $value;

    private DocModel $doc;

    /**
     * @param int|string $value
     */
    public function __construct(string $name, $value, ?DocModel $doc = null)
    {
        // A class constant (and an enum case) must not be called "class".
        if (!Identifier::isValid($name) || strtolower($name) === 'class') {
            throw new InvalidModel(sprintf('"%s" is not a usable enum case name.', $name));
        }

        if (!is_int($value) && !is_string($value)) {
            throw new InvalidModel(sprintf('Enum case "%s" must have an int or string value.', $name));
        }

        $this->name = $name;
        $this->value = $value;
        $this->doc = $doc ?? DocModel::none();
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * @return int|string
     */
    public function value()
    {
        return $this->value;
    }

    public function doc(): DocModel
    {
        return $this->doc;
    }
}
