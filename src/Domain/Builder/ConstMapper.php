<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Model\MixedType;
use MSSTC4PHP\DtoGenerator\Domain\Model\NullableType;
use MSSTC4PHP\DtoGenerator\Domain\Model\ScalarType;
use MSSTC4PHP\DtoGenerator\Domain\Model\TypeModel;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaType;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;

/**
 * The types `const` gives: a scalar with its PHPDoc literal, alone or narrowing the type of the rest of an `allOf`.
 *
 * @phpstan-import-type JsonValue from Json
 */
final class ConstMapper
{
    /** @var array<int|string, TypeModel> */
    private array $formats;

    /**
     * @param array<int|string, TypeModel> $formats custom formats of the config and the extensions
     */
    public function __construct(array $formats)
    {
        $this->formats = $formats;
    }

    /**
     * The type of the one allowed value, as the property holds it; null leaves the schema's own type: a format mapped to
     * a class or a date wins, as do null, array and object constants, and a declared type the constant contradicts.
     */
    public function type(Schema $schema, Diagnostics $diagnostics): ?ScalarType
    {
        $format = $schema->format();
        $declared = array_map(static fn (SchemaType $type): string => $type->value(), $schema->nonNullTypes());
        // A date applies only to a declared string; a configured format applies to any type.
        $dated = in_array($format, FormatCheck::DATE, true) && in_array(SchemaType::STRING, $declared, true);
        if ($format !== null && (isset($this->formats[$format]) || $dated)) {
            return null;
        }

        $value = Json::value($schema->keyword('const'));
        $allows = static fn (string $type): bool => $declared === [] || in_array($type, $declared, true);
        // JSON Schema counts 2.0 as an integer and every integer as a number.
        // The round trip leaves out fractions and floats beyond the range of int.
        $whole = is_int($value) || (is_float($value) && $value === (float) (int) $value) ? (int) $value : null;
        $integer = in_array(SchemaType::INTEGER, $declared, true);
        $number = in_array(SchemaType::NUMBER, $declared, true);
        if ($whole !== null && $integer) {
            $type = ScalarType::int(LiteralType::of($whole));
        } elseif (is_float($value) && $value === floor($value) && $integer && !$number) {
            $diagnostics->warning('"const" is outside the range of PHP int; the property keeps int.', $schema->location()->child('const'));

            return null;
        } elseif (is_int($value) && $declared === []) {
            $type = ScalarType::int(LiteralType::of($value));
        } elseif (is_int($value) || is_float($value)) {
            $type = $number || $declared === [] ? ScalarType::float() : null;
        } elseif (is_string($value)) {
            $type = $allows(SchemaType::STRING) ? ScalarType::string(LiteralType::of($value)) : null;
        } elseif (is_bool($value)) {
            $type = $allows(SchemaType::BOOLEAN) ? ScalarType::bool(LiteralType::of($value)) : null;
        } else {
            return null;
        }

        if (!$type instanceof ScalarType) {
            $diagnostics->warning('"const" is not of the declared type; the property keeps its declared type.', $schema->location()->child('const'));

            return null;
        }

        FormatCheck::check($schema, $type->kind(), $diagnostics);

        return $type;
    }

    /**
     * Each bare `const` member of `allOf` narrows the type the rest gives to its literal. A constant no value of that
     * type matches, or one other than an earlier constant, leaves the schema with no valid value; the type is kept and
     * the constant reported.
     */
    public function narrow(Schema $schema, TypeModel $base, Diagnostics $diagnostics): TypeModel
    {
        $type = $base instanceof NullableType ? $base->inner() : $base;
        /** @var list<JsonValue> $fixed the value an earlier constant fixed, if any */
        $fixed = [];
        foreach ($schema->allOf() as $member) {
            $literal = self::isBare($member) ? $this->type($member, $diagnostics) : null;
            if (!$literal instanceof ScalarType || !$type instanceof MixedType && !$type instanceof ScalarType) {
                continue;
            }

            $value = $this->whole(Json::value($member->keyword('const')));
            $other = $fixed !== [] && $fixed[0] !== $value;
            if ($other || ($type instanceof ScalarType && !DefaultFit::fits($value, $type))) {
                $diagnostics->warning(
                    '"const" is outside the type the rest of the schema gives, so no value is valid; the type is kept.',
                    $member->location()->child('const'),
                );

                continue;
            }

            $fixed = [$value];
            // A float keeps its type for an integer constant: the property holds 5.0 as well.
            if ($type instanceof MixedType || $type->kind() === $literal->kind()) {
                $type = $literal;
            }
        }

        return $base instanceof NullableType ? NullableType::of($type) : $type;
    }

    /**
     * JSON Schema counts 5.0 as the integer 5, so a whole float compares as one.
     *
     * @param JsonValue $value
     *
     * @return JsonValue
     */
    private function whole($value)
    {
        return is_float($value) ? $this->wholeFloat($value) : $value;
    }

    /**
     * @return int|float
     */
    private function wholeFloat(float $value)
    {
        return $value === (float) (int) $value ? (int) $value : $value;
    }

    /**
     * A member that only fixes the value; one with a type, a reference or a mapping gives the base type itself.
     */
    private function isBare(Schema $member): bool
    {
        return $member->hasKeyword('const')
            && $member->ref() === null
            && $member->nonNullTypes() === []
            && !$member->extensions()->has('x-php-type');
    }
}
