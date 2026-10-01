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
        $flat = [];
        $seen = [];
        foreach ($members as $member) {
            if ($member instanceof NullableType || $member instanceof MixedType) {
                throw new InvalidModel(sprintf('Union member "%s" must not be nullable or mixed; wrap the whole union in NullableType instead.', $member->describe()));
            }

            foreach ($member instanceof self ? $member->members() : [$member] as $part) {
                $key = $part->describe();
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $flat[] = $part;
                }
            }
        }

        if (count($flat) < 2) {
            throw new InvalidModel('A union type needs at least two distinct members.');
        }

        $this->members = $flat;
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
}
