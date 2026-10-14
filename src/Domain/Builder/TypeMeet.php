<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Builder;

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

/**
 * The type of the values two schemas both admit, when PHP can say it: every schema of an `allOf` applies, so a property
 * two of them describe has the narrower type. Types neither of which admits the other's values, but for the members of
 * a union, have no meet.
 */
final class TypeMeet
{
    /** @var list<string> FQCNs of the classes a formatted string becomes */
    private array $stringClasses;

    /**
     * @param list<string> $stringClasses
     */
    public function __construct(array $stringClasses)
    {
        $this->stringClasses = $stringClasses;
    }

    public function of(TypeModel $a, TypeModel $b): ?TypeModel
    {
        if ($this->admits($a, $b)) {
            return $b;
        }

        if ($this->admits($b, $a)) {
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

        $members = [];
        foreach ($a instanceof UnionType ? $a->members() : [$a] as $left) {
            foreach ($b instanceof UnionType ? $b->members() : [$b] as $right) {
                $meet = $a instanceof UnionType || $b instanceof UnionType ? $this->of($left, $right) : null;
                if ($meet instanceof TypeModel) {
                    $members[] = $meet;
                }
            }
        }

        return $members === [] ? null : UnionType::of(...$members);
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
            // A class is an object with properties of any type, which a map of any values admits.
            return ($narrow instanceof MapType && $this->admits($wide->value(), $narrow->value()))
                || ($narrow instanceof ClassType && $wide->value() instanceof MixedType && !$this->isString($narrow));
        }

        if ($wide instanceof EnumType) {
            return $narrow instanceof EnumType && $narrow->backing()->value() === $wide->backing()->value()
                && array_diff_key($narrow->cases(), $wide->cases()) === [];
        }

        return $wide instanceof ScalarType && $this->scalarAdmits($wide, $narrow);
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

        return $wide->phpDoc() === null && $wide->kind() === 'string' && $narrow instanceof ClassType && $this->isString($narrow);
    }

    private function isString(ClassType $type): bool
    {
        return in_array($type->className()->fqcn(), $this->stringClasses, true);
    }

    private function withoutNull(TypeModel $type): TypeModel
    {
        return $type instanceof NullableType ? $type->inner() : $type;
    }
}
