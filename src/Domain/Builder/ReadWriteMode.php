<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;
use MSSTC4PHP\DtoGenerator\Domain\Target\ReadWriteModels;

/**
 * The readWriteModels of one class (spec F4 §2): `x-php-read-write` on its own schema, else the run's.
 */
final class ReadWriteMode
{
    public const KEY = 'x-php-read-write';

    private ReadWriteModels $default;

    public function __construct(ReadWriteModels $default)
    {
        $this->default = $default;
    }

    /**
     * Whether the readOnly/writeOnly of the class's own properties count.
     */
    public function splits(Schema $schema): bool
    {
        return (self::declared($schema) ?? $this->default)->isSplit();
    }

    /**
     * @return ReadWriteModels|null null without the key or with a value it does not take
     */
    public static function declared(Schema $schema): ?ReadWriteModels
    {
        $value = $schema->extensions()->has(self::KEY) ? $schema->extensions()->get(self::KEY) : null;

        return is_string($value) ? ReadWriteModels::tryFrom($value) : null;
    }

    public static function check(Schema $schema, Diagnostics $diagnostics): void
    {
        if ($schema->extensions()->has(self::KEY) && !self::declared($schema) instanceof ReadWriteModels) {
            $diagnostics->error(sprintf('"%s" must be "single" or "split".', self::KEY), $schema->location()->child(self::KEY));
        }
    }
}
