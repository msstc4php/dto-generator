<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Model;

final class DocModel
{
    private ?string $description;

    private bool $deprecated;

    public function __construct(?string $description = null, bool $deprecated = false)
    {
        $trimmed = $description === null ? '' : trim($description);
        $this->description = $trimmed === '' ? null : $trimmed;
        $this->deprecated = $deprecated;
    }

    public static function none(): self
    {
        return new self();
    }

    public function description(): ?string
    {
        return $this->description;
    }

    public function isDeprecated(): bool
    {
        return $this->deprecated;
    }

    public function isEmpty(): bool
    {
        return $this->description === null && !$this->deprecated;
    }
}
