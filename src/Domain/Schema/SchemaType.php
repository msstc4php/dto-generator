<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Schema;

use MSSTC4PHP\DtoGenerator\Domain\Shared\AbstractEnum;

final class SchemaType extends AbstractEnum
{
    public const STRING = 'string';

    public const INTEGER = 'integer';

    public const NUMBER = 'number';

    public const BOOLEAN = 'boolean';

    public const ARRAY = 'array';

    public const OBJECT = 'object';

    public const NULL = 'null';

    protected static function values(): array
    {
        return [self::STRING, self::INTEGER, self::NUMBER, self::BOOLEAN, self::ARRAY, self::OBJECT, self::NULL];
    }

    public function isNull(): bool
    {
        return $this->value() === self::NULL;
    }
}
