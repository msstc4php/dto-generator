<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Model;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;

/**
 * A property typed by a generated enum. It keeps the case of every value, so a schema default can be emitted as
 * `Name::CASE` and checked against the enum.
 *
 * @phpstan-import-type JsonValue from Json
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

    public static function of(EnumModel $enum): self
    {
        $cases = [];
        foreach ($enum->cases() as $case) {
            $cases[$case->value()] = $case->name();
        }

        return new self($enum->name(), $enum->backing(), $cases);
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
     * The case of a value, or null for anything that is not one of the values (another type included).
     *
     * @param JsonValue $value
     */
    public function caseFor($value): ?string
    {
        if ($this->backing->value() === EnumBacking::INT) {
            return is_int($value) ? $this->cases[$value] ?? null : null;
        }

        return is_string($value) ? $this->cases[$value] ?? null : null;
    }

    public function describe(): string
    {
        return $this->className->fqcn();
    }
}
