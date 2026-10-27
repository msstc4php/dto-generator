<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\CollectionType;
use MSSTC4PHP\DtoGenerator\Domain\Model\EnumBacking;
use MSSTC4PHP\DtoGenerator\Domain\Model\EnumModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\EnumType;
use MSSTC4PHP\DtoGenerator\Domain\Model\NullableType;
use MSSTC4PHP\DtoGenerator\Domain\Model\ScalarType;
use MSSTC4PHP\DtoGenerator\Domain\Model\TypeModel;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;

/**
 * A collection class without type arguments fixes its items (`{item}` makes one per item type), so it may hold one item
 * type only; and a class this run generates is no collection. `{item}` is a short name, so items of two namespaces meet
 * here.
 */
final class CollectionClashes
{
    private function __construct()
    {
    }

    /**
     * @param list<ClassModel> $classes
     * @param list<EnumModel> $enums
     * @param bool $enumsSupported below PHP 8.1 an enum is held as its scalar, so its collection is the scalar's
     */
    public static function check(array $classes, array $enums, bool $enumsSupported, Diagnostics $diagnostics): void
    {
        $generated = [];
        foreach ($classes as $class) {
            $generated[strtolower($class->name()->fqcn())] = $class->source();
        }

        foreach ($enums as $enum) {
            $generated[strtolower($enum->name()->fqcn())] = $enum->source();
        }

        /** @var array<string, array{string, SchemaLocation}> $holders by lower-cased collection FQCN */
        $holders = [];
        // A list, not a map of true: a mutant writing false would survive isset().
        $reported = [];
        foreach ($classes as $class) {
            foreach ($class->properties() as $property) {
                $type = $property->type();
                $type = $type instanceof NullableType ? $type->inner() : $type;
                if (!$type instanceof CollectionType) {
                    continue;
                }

                $fqcn = $type->className()->fqcn();
                $key = strtolower($fqcn);
                if (in_array($key, $reported, true)) {
                    continue;
                }

                $at = $property->source();
                $generatedAt = $generated[$key] ?? null;
                if ($generatedAt instanceof SchemaLocation) {
                    $reported[] = $key;
                    $diagnostics->error(sprintf('The collection class %s is also generated from %s; set "x-php-collection" on the property or rename one of them.', $fqcn, $generatedAt->toString()), $at);

                    continue;
                }

                if ($type->typeArguments() !== []) {
                    continue;
                }

                $item = self::item($type->list()->item(), $enumsSupported);
                $holder = $holders[$key] ?? null;
                if ($holder === null) {
                    $holders[$key] = [$item, $at];
                } elseif ($holder[0] !== $item) {
                    $reported[] = $key;
                    $diagnostics->error(sprintf('The collection class %s holds %s here, but %s at %s; set "x-php-collection" on one of them.', $fqcn, $item, $holder[0], $holder[1]->toString()), $at);
                }
            }
        }
    }

    /**
     * The item type as the class name tells it: refinements of a scalar (`non-empty-string`) share its collection.
     */
    private static function item(TypeModel $item, bool $enumsSupported): string
    {
        if ($item instanceof EnumType && !$enumsSupported) {
            return $item->backing()->value() === EnumBacking::INT ? 'int' : 'string';
        }

        return $item instanceof ScalarType ? $item->kind() : $item->describe();
    }
}
