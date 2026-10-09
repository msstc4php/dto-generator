<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Model\EnumType;
use MSSTC4PHP\DtoGenerator\Domain\Model\ListType;
use MSSTC4PHP\DtoGenerator\Domain\Model\MixedType;
use MSSTC4PHP\DtoGenerator\Domain\Model\NullableType;
use MSSTC4PHP\DtoGenerator\Domain\Model\ScalarType;
use MSSTC4PHP\DtoGenerator\Domain\Model\TypeModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\UnionType;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;

/**
 * Whether a schema default is a valid value of the mapped PHP type, PHPDoc refinements included.
 *
 * @phpstan-import-type JsonValue from Json
 */
final class DefaultFit
{
    private function __construct()
    {
    }

    /**
     * @param JsonValue $value
     */
    public static function fits($value, TypeModel $type): bool
    {
        if ($type instanceof MixedType) {
            return true;
        }

        if ($type instanceof NullableType) {
            return $value === null || self::fits($value, $type->inner());
        }

        if ($type instanceof UnionType) {
            return self::anyFits($value, $type->members());
        }

        if ($type instanceof EnumType) {
            return $type->caseFor($value) !== null;
        }

        if ($type instanceof ListType) {
            return is_array($value) && Json::isList($value) && self::allFit($value, $type->item());
        }

        return $type instanceof ScalarType && self::fitsScalar($value, $type);
    }

    /**
     * @param JsonValue $value
     * @param list<TypeModel> $members
     */
    private static function anyFits($value, array $members): bool
    {
        foreach ($members as $member) {
            if (self::fits($value, $member)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<mixed> $items
     */
    private static function allFit(array $items, TypeModel $type): bool
    {
        foreach ($items as $item) {
            if (!self::fits(Json::value($item), $type)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param JsonValue $value
     */
    private static function fitsScalar($value, ScalarType $type): bool
    {
        $literal = $type->phpDoc() === null ? null : LiteralType::admits($type->phpDoc(), $value);
        if ($literal !== null) {
            return $literal;
        }

        switch ($type->kind()) {
            case 'int':
                return is_int($value) && self::inRange($value, $type->phpDoc());
            case 'float':
                return is_int($value) || is_float($value);
            case 'bool':
                return is_bool($value);
            default:
                return is_string($value) && ($value !== '' || $type->phpDoc() !== 'non-empty-string');
        }
    }

    private static function inRange(int $value, ?string $refinement): bool
    {
        if ($refinement === 'positive-int') {
            return $value >= 1;
        }

        if ($refinement === 'non-negative-int') {
            return $value >= 0;
        }

        if ($refinement === null || preg_match('/^int<(min|-?\d+), (max|-?\d+)>\z/', $refinement, $bounds) !== 1) {
            return true;
        }

        $min = $bounds[1] === 'min' ? PHP_INT_MIN : (int) $bounds[1];
        $max = $bounds[2] === 'max' ? PHP_INT_MAX : (int) $bounds[2];

        return $value >= $min && $value <= $max;
    }
}
