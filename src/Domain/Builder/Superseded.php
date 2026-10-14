<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Model\ClassType;
use MSSTC4PHP\DtoGenerator\Domain\Model\EnumType;
use MSSTC4PHP\DtoGenerator\Domain\Model\ListType;
use MSSTC4PHP\DtoGenerator\Domain\Model\MapType;
use MSSTC4PHP\DtoGenerator\Domain\Model\NullableType;
use MSSTC4PHP\DtoGenerator\Domain\Model\TypeModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\UnionType;

/**
 * The classes and enums of property types a build set aside, for another member's type in an allOf or for the
 * parent's declaration: generated for nothing else, they are left out.
 */
final class Superseded
{
    /** @var array<string, string> by FQCN; a class the kept type names too stays, as its own class reaches it */
    private array $names = [];

    public function add(TypeModel $type): void
    {
        foreach (self::named($type) as $name) {
            $this->names[$name] = $name;
        }
    }

    public function has(string $fqcn): bool
    {
        return isset($this->names[$fqcn]);
    }

    /**
     * @return list<string> the FQCNs of the classes and enums a type names
     */
    public static function named(TypeModel $type): array
    {
        $names = [];
        $types = [$type];
        while (($current = array_pop($types)) instanceof TypeModel) {
            if ($current instanceof ClassType || $current instanceof EnumType) {
                $names[] = $current->className()->fqcn();
            } elseif ($current instanceof NullableType) {
                $types[] = $current->inner();
            } elseif ($current instanceof ListType) {
                $types[] = $current->item();
            } elseif ($current instanceof MapType) {
                $types[] = $current->value();
            } elseif ($current instanceof UnionType) {
                array_push($types, ...$current->members());
            }
        }

        return $names;
    }
}
