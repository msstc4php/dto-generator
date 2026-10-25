<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Model\PropertyModel;

/**
 * A property every variant of a union declares alike, which the base takes.
 */
final class SharedProperty
{
    private PropertyModel $base;

    /** @var array<string, PropertyModel> */
    private array $declarations;

    /** @var list<string> */
    private array $requiring;

    /**
     * @param array<string, PropertyModel> $declarations each variant's, by FQCN
     * @param list<string> $requiring the variants that go on requiring it of the base, by FQCN
     */
    public function __construct(PropertyModel $base, array $declarations, array $requiring)
    {
        $this->base = $base;
        $this->declarations = $declarations;
        $this->requiring = $requiring;
    }

    public function base(): PropertyModel
    {
        return $this->base;
    }

    /**
     * @return array<string, PropertyModel>
     */
    public function declarations(): array
    {
        return $this->declarations;
    }

    /**
     * @return list<string>
     */
    public function requiring(): array
    {
        return $this->requiring;
    }
}
