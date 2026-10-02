<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Shared;

/**
 * Wraps a default so that "default is null" stays distinct from "no default".
 *
 * @phpstan-import-type JsonValue from Json
 */
final class DefaultValue
{
    /** @var JsonValue */
    private $value;

    /**
     * @param JsonValue $value
     */
    public function __construct($value)
    {
        $this->value = $value;
    }

    /**
     * @return JsonValue
     */
    public function value()
    {
        return $this->value;
    }

    /**
     * The value as JSON writes it, so `1` and `1.0` of a number compare equal.
     */
    public function toJson(): string
    {
        return (string) json_encode($this->value, JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
