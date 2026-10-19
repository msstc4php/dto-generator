<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassType;
use MSSTC4PHP\DtoGenerator\Domain\Model\EnumBacking;
use MSSTC4PHP\DtoGenerator\Domain\Model\EnumType;
use MSSTC4PHP\DtoGenerator\Domain\Model\ListType;
use MSSTC4PHP\DtoGenerator\Domain\Model\MapType;
use MSSTC4PHP\DtoGenerator\Domain\Model\MixedType;
use MSSTC4PHP\DtoGenerator\Domain\Model\NullableType;
use MSSTC4PHP\DtoGenerator\Domain\Model\ScalarType;
use MSSTC4PHP\DtoGenerator\Domain\Model\TypeModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\UnionType;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;

/**
 * The type of the values two schemas both admit, when PHP can say it: every schema of an `allOf` applies, so a property
 * two of them describe has the narrower type. Types neither of which admits the other's values, but for the members of
 * a union, have no meet.
 */
final class TypeMeet
{
    /** The class a string of a date format becomes. */
    private ClassName $dateTime;

    private Declarations $declarations;

    public function __construct(ClassName $dateTime, Declarations $declarations)
    {
        $this->dateTime = $dateTime;
        $this->declarations = $declarations;
    }

    public function of(TypeModel $a, TypeModel $b): ?TypeModel
    {
        $aAdmitsB = $this->admits($a, $b);
        $bAdmitsA = $this->admits($b, $a);
        // Types of the same values (float and float|int) meet in the one written shorter, then first in order, whichever
        // comes first.
        if ($aAdmitsB && $bAdmitsA) {
            $same = [$a, $b];
            usort($same, static fn (TypeModel $x, TypeModel $y): int => [strlen($x->describe()), $x->describe()] <=> [strlen($y->describe()), $y->describe()]);

            return $same[0];
        }

        if ($aAdmitsB) {
            return $b;
        }

        if ($bAdmitsA) {
            return $a;
        }

        if ($a instanceof NullableType || $b instanceof NullableType) {
            $meet = $this->of($this->withoutNull($a), $this->withoutNull($b));

            return !$meet instanceof TypeModel || !$a instanceof NullableType || !$b instanceof NullableType ? $meet : NullableType::of($meet);
        }

        if ($a instanceof ListType && $b instanceof ListType) {
            $item = $this->of($a->item(), $b->item());

            return $item instanceof TypeModel ? new ListType($item) : null;
        }

        if ($a instanceof MapType && $b instanceof MapType) {
            $value = $this->of($a->value(), $b->value());

            return $value instanceof TypeModel ? new MapType($value) : null;
        }

        if (!$a instanceof UnionType && !$b instanceof UnionType) {
            return null;
        }

        // A pair of members of another JSON kind shares no value and drops out; a pair that shares values PHP cannot
        // type (two classes) leaves the meet unknown, rather than narrower than the schema.
        $members = [];
        foreach ($a instanceof UnionType ? $a->members() : [$a] as $left) {
            foreach ($b instanceof UnionType ? $b->members() : [$b] as $right) {
                $meet = $this->of($left, $right);
                if ($meet instanceof TypeModel) {
                    $members[] = $meet;
                } elseif (!$this->disjoint($left, $right)) {
                    return null;
                }
            }
        }

        return $members === [] ? null : UnionType::of(...$members);
    }

    /**
     * Whether no value is of both types: they are of other JSON kinds. An int and a float always meet before this.
     */
    private function disjoint(TypeModel $a, TypeModel $b): bool
    {
        $kinds = [$this->kind($a), $this->kind($b)];

        return !in_array(null, $kinds, true) && $kinds[0] !== $kinds[1];
    }

    /**
     * The JSON kind of a type's values; null when PHP does not tell (mixed, a class a format or x-php-type names).
     */
    private function kind(TypeModel $type): ?string
    {
        if ($type instanceof ScalarType) {
            return ['string' => 'string', 'int' => 'integer', 'float' => 'number', 'bool' => 'boolean'][$type->kind()];
        }

        if ($type instanceof EnumType) {
            return $type->backing()->value() === EnumBacking::INT ? 'integer' : 'string';
        }

        if ($type instanceof ListType) {
            return 'array';
        }

        if ($type instanceof MapType || ($type instanceof ClassType && $this->declarations->generates($type->className()))) {
            return 'object';
        }

        return $type instanceof ClassType && $type->className()->fqcn() === $this->dateTime->fqcn() ? 'string' : null;
    }

    /**
     * Whether every value of the narrow type is one of the wide type.
     */
    public function admits(TypeModel $wide, TypeModel $narrow): bool
    {
        if ($wide instanceof MixedType || $wide->describe() === $narrow->describe()) {
            return true;
        }

        if ($narrow instanceof UnionType) {
            return array_filter($narrow->members(), fn (TypeModel $member): bool => !$this->admits($wide, $member)) === [];
        }

        if ($narrow instanceof NullableType) {
            return $wide instanceof NullableType && $this->admits($wide->inner(), $narrow->inner());
        }

        if ($wide instanceof NullableType) {
            return $this->admits($wide->inner(), $narrow);
        }

        if ($wide instanceof UnionType) {
            return array_filter($wide->members(), fn (TypeModel $member): bool => $this->admits($member, $narrow)) !== [];
        }

        if ($wide instanceof ListType) {
            return $narrow instanceof ListType && $this->admits($wide->item(), $narrow->item());
        }

        if ($wide instanceof MapType) {
            // A class the build generates is an object with properties of any type, which a map of any values admits; a
            // class a format or x-php-type names may stand for a value of another JSON type.
            return ($narrow instanceof MapType && $this->admits($wide->value(), $narrow->value()))
                || ($narrow instanceof ClassType && $wide->value() instanceof MixedType && $this->declarations->generates($narrow->className()));
        }

        if ($wide instanceof EnumType) {
            return $narrow instanceof EnumType && $narrow->backing()->value() === $wide->backing()->value()
                && array_diff_key($narrow->cases(), $wide->cases()) === [];
        }

        if ($wide instanceof ClassType) {
            return $narrow instanceof ClassType && $this->classAdmits($wide->className(), $narrow->className());
        }

        return $wide instanceof ScalarType && $this->scalarAdmits($wide, $narrow);
    }

    private function classAdmits(ClassName $wide, ClassName $narrow): bool
    {
        $wideSchema = $this->declarations->schemaOf($wide);
        $narrowSchema = $this->declarations->schemaOf($narrow);

        return $this->declarations->isWider($wide, $narrow)
            || ($wideSchema instanceof Schema && $narrowSchema instanceof Schema && ObjectShape::admits($wideSchema, $narrowSchema));
    }

    private function scalarAdmits(ScalarType $wide, TypeModel $narrow): bool
    {
        if ($narrow instanceof ScalarType) {
            return ($wide->phpDoc() === null && $narrow->kind() === $wide->kind())
                || ($wide->phpDoc() === null && $wide->kind() === 'float' && $narrow->kind() === 'int');
        }

        if ($narrow instanceof EnumType) {
            return $wide->phpDoc() === null && $wide->kind() === ($narrow->backing()->value() === EnumBacking::INT ? 'int' : 'string');
        }

        return $wide->phpDoc() === null && $wide->kind() === 'string' && $narrow instanceof ClassType && $narrow->className()->fqcn() === $this->dateTime->fqcn();
    }

    private function withoutNull(TypeModel $type): TypeModel
    {
        return $type instanceof NullableType ? $type->inner() : $type;
    }
}
