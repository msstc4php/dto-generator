<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Schema\Discriminator;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaType;
use MSSTC4PHP\DtoGenerator\Domain\Shared\DefaultValue;

/**
 * Everything a schema says, its subschemas included, apart from where it is: two schemas with one fingerprint in one
 * file give the same class. Each `$ref` counts as written, so only within one file does it name the same target.
 */
final class SchemaFingerprint
{
    private function __construct()
    {
    }

    public static function of(Schema $schema): string
    {
        $properties = [];
        foreach ($schema->propertyNames() as $name) {
            $properties[] = [$name, self::of($schema->requireProperty($name))];
        }

        $items = $schema->items();
        $additional = $schema->additionalProperties();
        $default = $schema->default();
        $discriminator = $schema->discriminator();

        return serialize([
            array_map(static fn (SchemaType $type): string => $type->value(), $schema->types()),
            $schema->ref(),
            $schema->format(),
            $schema->description(),
            $schema->isDeprecated(),
            $default instanceof DefaultValue ? $default->toJson() : null,
            $schema->enum(),
            $properties,
            $schema->required(),
            $items instanceof Schema ? self::of($items) : null,
            $additional instanceof Schema ? self::of($additional) : $additional,
            self::all($schema->allOf()),
            self::all($schema->oneOf()),
            self::all($schema->anyOf()),
            $discriminator instanceof Discriminator
                ? [$discriminator->propertyName(), array_map(static fn (string $value): array => [$value, $discriminator->refFor($value)], $discriminator->values())]
                : null,
            $schema->keywords(),
            $schema->extensions()->all(),
        ]);
    }

    /**
     * @param list<Schema> $schemas
     *
     * @return list<string>
     */
    private static function all(array $schemas): array
    {
        return array_map([self::class, 'of'], $schemas);
    }
}
