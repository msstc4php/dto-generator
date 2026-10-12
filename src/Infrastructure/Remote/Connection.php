<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Infrastructure\Remote;

use MSSTC4PHP\DtoGenerator\Application\Config\RemoteHeader;

/**
 * A socket under one deadline, which covers every operation on it: connecting, TLS, writing and reading. PHP's own
 * timeouts apply per operation, so each one gets what is left of the deadline.
 */
final class Connection
{
    private const MAX_HEADER_BYTES = 65536;

    /** @var resource */
    private $stream;

    private float $deadline;

    /** @var positive-int */
    private int $timeout;

    /**
     * @param resource $stream
     * @param positive-int $timeout
     */
    private function __construct($stream, float $deadline, int $timeout)
    {
        $this->stream = $stream;
        $this->deadline = $deadline;
        $this->timeout = $timeout;
    }

    /**
     * TCP to the host, with the TLS settings for the peer, which a tunnel through a proxy reaches later; a failure
     * explains itself only in warnings, which are kept.
     *
     * @param string $peer the server's host, whose certificate TLS checks
     * @param string|null $caFile the certificates to trust instead of the system's
     * @param positive-int $timeout seconds from now
     * @param string $failure what a failure message starts with
     */
    public static function open(string $host, int $port, string $peer, ?string $caFile, int $timeout, string $failure): self
    {
        $deadline = microtime(true) + $timeout;
        $methods = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT : 0);
        $ssl = ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => trim($peer, '[]'), 'SNI_enabled' => true, 'crypto_method' => $methods];
        if ($caFile !== null) {
            $ssl['cafile'] = $caFile;
        }

        $warnings = [];
        set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        });
        try {
            $stream = stream_socket_client('tcp://' . $host . ':' . $port, $errno, $error, max(0.001, $deadline - microtime(true)), STREAM_CLIENT_CONNECT, stream_context_create(['ssl' => $ssl]));
        } finally {
            restore_error_handler();
        }

        if (!is_resource($stream)) {
            throw new FetchFailed($failure . ($warnings === [] ? (($error ?? '') === '' ? 'the connection failed' : (string) $error) : implode('; ', $warnings)));
        }

        return new self($stream, $deadline, $timeout);
    }

    /**
     * TLS 1.2 or newer with the peer the connection was opened for.
     */
    public function secure(): void
    {
        $warnings = [];
        set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        });
        try {
            $secured = $this->handshake();
        } finally {
            restore_error_handler();
        }

        if ($secured === null) {
            throw $this->late();
        }

        if (!$secured) {
            throw new FetchFailed($warnings === [] ? 'the TLS handshake failed' : implode('; ', $warnings));
        }
    }

    public function write(string $data): void
    {
        $this->limit();
        fwrite($this->stream, $data);
    }

    /**
     * The status line and the header fields of an answer, the server's or a proxy's.
     *
     * @return array{int, list<array{string, string}>} the status and each field as lower-case name and value
     */
    public function head(): array
    {
        $status = 0;
        $headers = [];
        $headerBytes = 0;
        for ($first = true; ($line = $this->line()) !== ''; $first = false) {
            $headerBytes += strlen($line);
            if ($headerBytes > self::MAX_HEADER_BYTES) {
                throw new FetchFailed(sprintf('its headers are larger than %d bytes', self::MAX_HEADER_BYTES));
            }

            // They would reach the diagnostics, and a terminal, as they are.
            if (RemoteHeader::hasControlCharacters($line)) {
                throw new FetchFailed('its headers hold control characters');
            }

            if ($first) {
                if (preg_match('#^HTTP/\d\.\d (\d{3})#', $line, $matches) !== 1) {
                    throw new FetchFailed('the server did not answer in HTTP');
                }

                $status = (int) $matches[1];
            } elseif (preg_match('/^(' . RemoteHeader::TOKEN . '):[ \t]*(.*?)[ \t]*$/', $line, $matches) !== 1) {
                // "Transfer-Encoding : chunked" would otherwise pass unseen (RFC 9112 §5.1); folded lines are refused too.
                throw new FetchFailed('its headers are malformed');
            } else {
                $headers[] = [strtolower($matches[1]), $matches[2]];
            }
        }

        return [$status, $headers];
    }

    /**
     * The body up to its announced length, else to the end of the stream.
     *
     * @param positive-int $maxBytes
     */
    public function body(?int $length, int $maxBytes): string
    {
        $body = '';
        while (!feof($this->stream) && ($length === null || strlen($body) < $length)) {
            $body .= $this->read($length === null ? 8192 : max(1, min(8192, $length - strlen($body))));
            if (strlen($body) > $maxBytes) {
                throw new FetchFailed(sprintf('it is larger than %d bytes', $maxBytes));
            }
        }

        // A short body would be cached, and its hash would vouch for it.
        if ($length !== null && strlen($body) < $length) {
            throw new FetchFailed(sprintf('it ended after %d of %d bytes', strlen($body), $length));
        }

        return $body;
    }

    public function close(): void
    {
        fclose($this->stream);
    }

    /**
     * Non-blocking under the deadline: a blocking handshake is bounded by the connect timeout instead, a second one.
     * Without a method the context's crypto_method applies: TLS 1.2 or newer.
     *
     * Only the read side is awaited: a handshake's writes fit the socket buffer.
     *
     * @return bool|null null when the deadline passed
     */
    private function handshake(): ?bool
    {
        stream_set_blocking($this->stream, false);
        try {
            while (true) {
                $done = stream_socket_enable_crypto($this->stream, true);
                if ($done !== 0) {
                    return $done;
                }

                $left = $this->deadline - microtime(true);
                if ($left <= 0) {
                    return null;
                }

                $read = [$this->stream];
                $write = null;
                $except = null;
                stream_select($read, $write, $except, (int) $left, (int) (($left - (int) $left) * 1000000));
            }
        } finally {
            stream_set_blocking($this->stream, true);
        }
    }

    /**
     * One header line without its line break; empty at the end of the headers or of the stream.
     */
    private function line(): string
    {
        $line = '';
        while (!feof($this->stream) && substr($line, -1) !== "\n") {
            $line .= $this->read(1);
            if (strlen($line) > self::MAX_HEADER_BYTES) {
                throw new FetchFailed(sprintf('its headers are larger than %d bytes', self::MAX_HEADER_BYTES));
            }
        }

        return rtrim($line, "\r\n");
    }

    /**
     * @param positive-int $length
     */
    private function read(int $length): string
    {
        $this->limit();
        $chunk = fread($this->stream, $length);
        if (stream_get_meta_data($this->stream)['timed_out']) {
            throw $this->late();
        }

        return is_string($chunk) ? $chunk : '';
    }

    /**
     * Gives the next socket operation what is left of the deadline.
     */
    private function limit(): void
    {
        $left = $this->deadline - microtime(true);
        if ($left <= 0) {
            throw $this->late();
        }

        stream_set_timeout($this->stream, (int) $left, (int) (($left - (int) $left) * 1000000));
    }

    private function late(): FetchFailed
    {
        return new FetchFailed(sprintf('it took longer than %d seconds', $this->timeout));
    }
}
