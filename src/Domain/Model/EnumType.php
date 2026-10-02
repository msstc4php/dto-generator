<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Model;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;

/**
 * A property typed by a generated enum. It keeps the case of every value, so a schema default can be emitted as
 * `Name::CASE` and checked against the enum.
 */
final class EnumType implements TypeModel
{
    private ClassName $className;

    private EnumBacking $backing;

    /** @var array<int|string, string> */
    private array $cases;

    /**
     * @param array<int|string, string> $cases value → case name
     */
    public function __construct(ClassName $className, EnumBacking $backing, array $cases)
    {
        if ($cases === []) {
            throw new InvalidModel(sprintf('Enum %s has no cases.', $className->fqcn()));
        }

        $this->className = $className;
        $this->backing = $backing;
        $this->cases = $cases;
    }

    public function className(): ClassName
    {
        return $this->className;
    }

    public function backing(): EnumBacking
    {
        return $this->backing;
    }

    /**
     * @return array<int|string, string>
     */
    public function cases(): array
    {
        return $this->cases;
    }

    /**
     * @param int|string $value
     */
    public function caseFor($value): ?string
    {
        $isInt = $this->backing->value() === EnumBacking::INT;
        if ($isInt ? !is_int($value) : !is_string($value)) {
            return null;
        }

        return $this->cases[$value] ?? null;
    }

    public function describe(): string
    {
        return $this->className->fqcn();
    }
}
