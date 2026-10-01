<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Target;

use LogicException;
use MSSTC4PHP\DtoGenerator\Domain\Shared\AbstractEnum;

final class Capability extends AbstractEnum
{
    public const TYPED_PROPERTIES = 'typed-properties';

    public const CONSTRUCTOR_PROMOTION = 'constructor-promotion';

    public const UNION_TYPES = 'union-types';

    public const ATTRIBUTES = 'attributes';

    public const MIXED_TYPE = 'mixed-type';

    public const READONLY_PROPERTIES = 'readonly-properties';

    public const ENUMS = 'enums';

    public const NEW_IN_INITIALIZERS = 'new-in-initializers';

    public const READONLY_CLASSES = 'readonly-classes';

    public const STANDALONE_NULL_FALSE = 'standalone-null-false';

    public const TYPED_CLASS_CONSTANTS = 'typed-class-constants';

    public const ASYMMETRIC_VISIBILITY = 'asymmetric-visibility';

    public const PROPERTY_HOOKS = 'property-hooks';

    public const CLONE_WITH = 'clone-with';

    private const MINIMUM_VERSION = [
        self::TYPED_PROPERTIES => '7.4',
        self::CONSTRUCTOR_PROMOTION => '8.0',
        self::UNION_TYPES => '8.0',
        self::ATTRIBUTES => '8.0',
        self::MIXED_TYPE => '8.0',
        self::READONLY_PROPERTIES => '8.1',
        self::ENUMS => '8.1',
        self::NEW_IN_INITIALIZERS => '8.1',
        self::READONLY_CLASSES => '8.2',
        self::STANDALONE_NULL_FALSE => '8.2',
        self::TYPED_CLASS_CONSTANTS => '8.3',
        self::ASYMMETRIC_VISIBILITY => '8.4',
        self::PROPERTY_HOOKS => '8.4',
        self::CLONE_WITH => '8.5',
    ];

    public function minimumVersion(): PhpVersion
    {
        $version = self::MINIMUM_VERSION[$this->value()] ?? null;
        if ($version === null) {
            throw new LogicException(sprintf('Capability "%s" has no minimum PHP version.', $this->value()));
        }

        return PhpVersion::fromString($version);
    }

    protected static function values(): array
    {
        return array_keys(self::MINIMUM_VERSION);
    }
}
