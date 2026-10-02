<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Model;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;

final class UnionType implements TypeModel
{
    /** @var non-empty-list<TypeModel> */
    private array $members;

    public function __construct(TypeModel ...$members)
    {
        $flat = self::distinct($members);
        if (count($flat) < 2) {
            throw new InvalidModel('A union type needs at least two distinct members.');
        }

        $this->members = $flat;
    }

    /**
     * The union of the members, or the only one left once duplicates collapse.
     */
    public static function of(TypeModel $first, TypeModel ...$others): TypeModel
    {
        $flat = self::distinct(array_merge([$first], $others));

        return count($flat) === 1 ? $flat[0] : new self(...$flat);
    }

    /**
     * @return non-empty-list<TypeModel>
     */
    public function members(): array
    {
        return $this->members;
    }

    public function describe(): string
    {
        return implode('|', array_map(static fn (TypeModel $member): string => $member->describe(), $this->members));
    }

    /**
     * Nested unions flattened, duplicates dropped.
     *
     * @param list<TypeModel> $members
     *
     * @return list<TypeModel>
     */
    private static function distinct(array $members): array
    {
        $flat = [];
        $seen = [];
        foreach ($members as $member) {
            if ($member instanceof NullableType || $member instanceof MixedType) {
                throw new InvalidModel(sprintf('Union member "%s" must not be nullable or mixed; wrap the whole union in NullableType instead.', $member->describe()));
            }

            foreach ($member instanceof self ? $member->members() : [$member] as $part) {
                $key = self::identityKey($part);
                if (!isset($seen[$key])) {
                    $seen[$key] = $part;
                    $flat[] = $part;
                }
            }
        }

        return $flat;
    }

    /**
     * Class names are case-insensitive in PHP, PHPDoc literals and constants are not: fold only the former.
     */
    private static function identityKey(TypeModel $type): string
    {
        return self::foldClassNames($type)->describe();
    }

    private static function foldClassNames(TypeModel $type): TypeModel
    {
        if ($type instanceof ClassType) {
            return new ClassType(ClassName::fromFqcn(Identifier::asciiLower($type->className()->fqcn())));
        }

        if ($type instanceof ListType) {
            return new ListType(self::foldClassNames($type->item()));
        }

        if ($type instanceof MapType) {
            return new MapType(self::foldClassNames($type->value()));
        }

        if ($type instanceof NullableType) {
            return new NullableType(self::foldClassNames($type->inner()));
        }

        if ($type instanceof self) {
            return new self(...array_map(static fn (TypeModel $member): TypeModel => self::foldClassNames($member), $type->members()));
        }

        return $type;
    }
}
