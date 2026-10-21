<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Schema\Discriminator;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaType;
use MSSTC4PHP\DtoGenerator\Domain\Shared\DefaultValue;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;

/**
 * Everything a schema says, its subschemas included, apart from where it is and its own title: two schemas with
 * one fingerprint in one file give the same class. Each `$ref` counts as written, so only within one file does it name the same target.
 *
 * @phpstan-import-type JsonValue from Json
 */
final class SchemaFingerprint
{
    private function __construct()
    {
    }

    /**
     * The schema's own title only names its class, and a schema that lends its class has named it already; the titles
     * of subschemas are kept, as some of them name classes of their own.
     */
    public static function of(Schema $schema): string
    {
        $keywords = $schema->keywords();
        unset($keywords['title']);

        return self::fingerprint($schema, $keywords);
    }

    private static function whole(Schema $schema): string
    {
        return self::fingerprint($schema, $schema->keywords());
    }

    /**
     * @param array<array-key, JsonValue> $keywords
     */
    private static function fingerprint(Schema $schema, array $keywords): string
    {
        $properties = [];
        foreach ($schema->propertyNames() as $name) {
            $properties[] = [$name, self::whole($schema->requireProperty($name))];
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
            self::sortedNames($schema->required()),
            $items instanceof Schema ? self::whole($items) : null,
            $additional instanceof Schema ? self::whole($additional) : $additional,
            self::all($schema->allOf()),
            self::all($schema->oneOf()),
            self::all($schema->anyOf()),
            $discriminator instanceof Discriminator
                ? [$discriminator->propertyName(), array_map(static fn (string $value): array => [$value, $discriminator->refFor($value)], $discriminator->values())]
                : null,
            self::sorted($keywords),
            self::sorted($schema->extensions()->all()),
        ]);
    }

    /**
     * Keywords in any order say the same; the order of properties is that of the constructor's parameters, so it counts.
     *
     * @param array<array-key, JsonValue> $keywords
     *
     * @return array<array-key, JsonValue>
     */
    private static function sorted(array $keywords): array
    {
        ksort($keywords, SORT_STRING);

        return $keywords;
    }

    /**
     * @param list<string> $names
     *
     * @return list<string>
     */
    private static function sortedNames(array $names): array
    {
        sort($names, SORT_STRING);

        return $names;
    }

    /**
     * @param list<Schema> $schemas
     *
     * @return list<string>
     */
    private static function all(array $schemas): array
    {
        return array_map(static fn (Schema $schema): string => self::whole($schema), $schemas);
    }
}
