<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Config;

use MSSTC4PHP\DtoGenerator\Domain\Target\MetadataMode;
use MSSTC4PHP\DtoGenerator\Domain\Target\PhpVersion;

final class TargetSettings
{
    private ?PhpVersion $php;

    private ?MetadataMode $metadata;

    private bool $strict;

    /**
     * @param PhpVersion|null $php null for "auto"
     * @param MetadataMode|null $metadata null for "auto"
     */
    public function __construct(?PhpVersion $php, ?MetadataMode $metadata, bool $strict)
    {
        $this->php = $php;
        $this->metadata = $metadata;
        $this->strict = $strict;
    }

    public function php(): ?PhpVersion
    {
        return $this->php;
    }

    public function metadata(): ?MetadataMode
    {
        return $this->metadata;
    }

    public function isStrict(): bool
    {
        return $this->strict;
    }
}
