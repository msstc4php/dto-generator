<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Infrastructure\Document;

use RuntimeException;

/**
 * Content that is no OpenAPI document: malformed, or not an object at the top level.
 */
final class UndecodableDocument extends RuntimeException
{
    private bool $malformed;

    private function __construct(string $reason, bool $malformed)
    {
        parent::__construct($reason);
        $this->malformed = $malformed;
    }

    public static function malformed(string $reason): self
    {
        return new self($reason, true);
    }

    public static function notAnObject(): self
    {
        return new self('must contain an object at the top level', false);
    }

    public function isMalformed(): bool
    {
        return $this->malformed;
    }
}
