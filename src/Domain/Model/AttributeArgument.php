<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Model;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;

/**
 * @api
 */
final class AttributeArgument
{
    private ?string $name;

    private ArgumentValue $value;

    private function __construct(?string $name, ArgumentValue $value)
    {
        $this->name = $name;
        $this->value = $value;
    }

    public static function positional(ArgumentValue $value): self
    {
        return new self(null, $value);
    }

    public static function named(string $name, ArgumentValue $value): self
    {
        if (!Identifier::isValid($name)) {
            throw new InvalidModel(sprintf('Argument name "%s" is not a PHP identifier.', $name));
        }

        return new self($name, $value);
    }

    /**
     * PHP's own call rules: positional arguments first, each name once.
     *
     * @param list<self> $arguments
     */
    public static function assertWellFormed(array $arguments): void
    {
        $named = [];
        foreach ($arguments as $argument) {
            if ($argument->name === null) {
                if ($named !== []) {
                    throw new InvalidModel('Positional argument after named arguments.');
                }

                continue;
            }

            if (isset($named[$argument->name])) {
                throw new InvalidModel(sprintf('Named argument "%s" is repeated.', $argument->name));
            }

            $named[$argument->name] = true;
        }
    }

    public function name(): ?string
    {
        return $this->name;
    }

    public function value(): ArgumentValue
    {
        return $this->value;
    }

    public function isNamed(): bool
    {
        return $this->name !== null;
    }
}
