<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Infrastructure\Remote;

/**
 * An HTTP proxy from the environment (spec F3 §3).
 */
final class Proxy
{
    private string $host;

    private int $port;

    private ?string $authorization;

    /**
     * @param string $host a name or an IPv6 address in brackets
     * @param string|null $authorization the value of Proxy-Authorization
     */
    public function __construct(string $host, int $port, ?string $authorization)
    {
        $this->host = $host;
        $this->port = $port;
        $this->authorization = $authorization;
    }

    public function host(): string
    {
        return $this->host;
    }

    public function port(): int
    {
        return $this->port;
    }

    public function authorization(): ?string
    {
        return $this->authorization;
    }

    /**
     * How messages name the proxy: never with its credentials.
     */
    public function label(): string
    {
        return $this->host . ':' . $this->port;
    }
}
