<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Target;

use MSSTC4PHP\DtoGenerator\Domain\Shared\AbstractEnum;

/**
 * @api
 */
final class MetadataMode extends AbstractEnum
{
    public const ATTRIBUTES = 'attributes';

    public const ANNOTATIONS = 'annotations';

    public const NONE = 'none';

    public static function defaultFor(PhpVersion $php): self
    {
        return $php->isAtLeast(Capability::from(Capability::ATTRIBUTES)->minimumVersion())
            ? self::from(self::ATTRIBUTES)
            : self::from(self::ANNOTATIONS);
    }

    protected static function values(): array
    {
        return [self::ATTRIBUTES, self::ANNOTATIONS, self::NONE];
    }

    public function isAttributes(): bool
    {
        return $this->value() === self::ATTRIBUTES;
    }

    public function isAnnotations(): bool
    {
        return $this->value() === self::ANNOTATIONS;
    }

    public function isNone(): bool
    {
        return $this->value() === self::NONE;
    }
}
