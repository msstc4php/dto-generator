<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Schema\Discriminator;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaType;

final class SchemaShape
{
    private function __construct()
    {
    }

    /**
     * The first keyword the builder cannot handle yet; composition, enums and maps of schemas arrive in stage 4.
     */
    public static function unsupportedKeyword(Schema $schema): ?string
    {
        if ($schema->enum() !== null) {
            return 'enum';
        }

        foreach (['allOf' => $schema->allOf(), 'oneOf' => $schema->oneOf(), 'anyOf' => $schema->anyOf()] as $keyword => $schemas) {
            if ($schemas !== []) {
                return $keyword;
            }
        }

        if ($schema->discriminator() instanceof Discriminator) {
            return 'discriminator';
        }

        return $schema->additionalProperties() instanceof Schema ? 'additionalProperties' : null;
    }

    /**
     * A list of values the builder turns into an enum; a reference, an explicit PHP type or a composition wins over it.
     */
    public static function isEnum(Schema $schema): bool
    {
        return $schema->enum() !== null
            && $schema->ref() === null
            && !$schema->extensions()->has('x-php-type')
            && $schema->allOf() === []
            && $schema->oneOf() === []
            && $schema->anyOf() === [];
    }

    /**
     * An object with its own properties, which the builder turns into a class; x-php-type maps it to an existing class instead.
     */
    public static function isClass(Schema $schema): bool
    {
        if ($schema->ref() !== null || $schema->extensions()->has('x-php-type') || self::unsupportedKeyword($schema) !== null || $schema->propertyNames() === []) {
            return false;
        }

        $types = $schema->nonNullTypes();

        return $types === [] || $types === [SchemaType::from(SchemaType::OBJECT)];
    }
}
