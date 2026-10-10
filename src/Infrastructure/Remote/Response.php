<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Infrastructure\Remote;

/**
 * What a server answered for one URL.
 */
final class Response
{
    private int $status;

    private ?string $contentType;

    private ?string $location;

    private string $body;

    public function __construct(int $status, ?string $contentType, ?string $location, string $body)
    {
        $this->status = $status;
        $this->contentType = $contentType;
        $this->location = $location;
        $this->body = $body;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function contentType(): ?string
    {
        return $this->contentType;
    }

    public function location(): ?string
    {
        return $this->location;
    }

    public function body(): string
    {
        return $this->body;
    }
}
