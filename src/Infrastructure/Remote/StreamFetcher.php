<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Infrastructure\Remote;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Url;

/**
 * One HTTP/1.0 GET over a socket: PHP's http wrapper bounds neither the time of a whole response (its timeout is per
 * read) nor the size of its headers, and both are the server's to choose. HTTP/1.0 keeps the body free of chunks.
 */
final class StreamFetcher implements Fetcher
{
    private const MAX_HEADER_BYTES = 65536;

    public function get(string $url, int $timeout, int $maxBytes): Response
    {
        // Only the spelling the allow list checked, which also keeps line breaks out of the request.
        $parts = $this->isNormalized($url) ? parse_url($url) : false;
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new FetchFailed(sprintf('"%s" is no normalized http(s) URL', $url));
        }

        $deadline = microtime(true) + $timeout;
        $secure = $parts['scheme'] === 'https';
        $port = $parts['port'] ?? ($secure ? 443 : 80);
        $stream = $this->connect($parts['host'], $port, $secure, $deadline, $timeout);
        try {
            $target = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
            $host = $parts['host'] . (isset($parts['port']) ? ':' . $port : '');
            $this->limit($stream, $deadline, $timeout);
            fwrite($stream, "GET {$target} HTTP/1.0\r\nHost: {$host}\r\nAccept: application/json, application/yaml;q=0.9, */*;q=0.5\r\nUser-Agent: msstc4php-dto-generator\r\nConnection: close\r\n\r\n");

            return $this->response($stream, $deadline, $timeout, $maxBytes);
        } finally {
            fclose($stream);
        }
    }

    private function isNormalized(string $url): bool
    {
        try {
            return Url::normalize($url) === $url;
        } catch (InvalidModel $exception) {
            return false;
        }
    }

    /**
     * TCP first, then TLS 1.2 or newer within what is left of the deadline; a failed handshake explains itself only in
     * warnings, which are kept.
     *
     * @param positive-int $timeout
     *
     * @return resource
     */
    private function connect(string $host, int $port, bool $secure, float $deadline, int $timeout)
    {
        $name = trim($host, '[]');
        $methods = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT : 0);
        $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $name, 'SNI_enabled' => true, 'crypto_method' => $methods]]);
        $warnings = [];
        set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        });
        try {
            $stream = stream_socket_client('tcp://' . $host . ':' . $port, $errno, $error, max(0.001, $deadline - microtime(true)), STREAM_CLIENT_CONNECT, $context);
            $secured = is_resource($stream) && $secure ? $this->handshake($stream, $deadline) : true;
            if ($secured !== true) {
                fclose($stream);
                $stream = $secured;
            }
        } finally {
            restore_error_handler();
        }

        if ($stream === null) {
            throw new FetchFailed(sprintf('it took longer than %d seconds', $timeout));
        }

        if (!is_resource($stream)) {
            throw new FetchFailed($warnings === [] ? (($error ?? '') === '' ? 'the connection failed' : (string) $error) : implode('; ', $warnings));
        }

        return $stream;
    }

    /**
     * Non-blocking under the deadline: a blocking handshake is bounded by the connect timeout instead, a second one.
     * Without a method the context's crypto_method applies: TLS 1.2 or newer.
     *
     * Only the read side is awaited: a handshake's writes fit the socket buffer.
     *
     * @param resource $stream
     *
     * @return bool|null null when the deadline passed
     */
    private function handshake($stream, float $deadline): ?bool
    {
        stream_set_blocking($stream, false);
        try {
            while (true) {
                $done = stream_socket_enable_crypto($stream, true);
                if ($done !== 0) {
                    return $done;
                }

                $left = $deadline - microtime(true);
                if ($left <= 0) {
                    return null;
                }

                $read = [$stream];
                $write = null;
                $except = null;
                stream_select($read, $write, $except, (int) $left, (int) (($left - (int) $left) * 1000000));
            }
        } finally {
            stream_set_blocking($stream, true);
        }
    }

    /**
     * @param resource $stream
     * @param positive-int $timeout
     * @param positive-int $maxBytes
     */
    private function response($stream, float $deadline, int $timeout, int $maxBytes): Response
    {
        $status = 0;
        $contentType = null;
        $location = null;
        $lengths = [];
        $headerBytes = 0;
        $transferEncoding = null;
        for ($first = true; ($line = $this->line($stream, $deadline, $timeout)) !== ''; $first = false) {
            $headerBytes += strlen($line);
            if ($headerBytes > self::MAX_HEADER_BYTES) {
                throw new FetchFailed(sprintf('its headers are larger than %d bytes', self::MAX_HEADER_BYTES));
            }

            // They would reach the diagnostics, and a terminal, as they are.
            if (preg_match('#[\x00-\x08\x0A-\x1F\x7F]#', $line) === 1) {
                throw new FetchFailed('its headers hold control characters');
            }

            if ($first) {
                if (preg_match('#^HTTP/\d\.\d (\d{3})#', $line, $matches) !== 1) {
                    throw new FetchFailed('the server did not answer in HTTP');
                }

                $status = (int) $matches[1];
            } elseif (preg_match('#^([!\#$%&\'*+.^_`|~0-9A-Za-z-]+):[ \t]*(.*?)[ \t]*$#', $line, $matches) !== 1) {
                // "Transfer-Encoding : chunked" would otherwise pass unseen (RFC 9112 §5.1); folded lines are refused too.
                throw new FetchFailed('its headers are malformed');
            } else {
                $header = strtolower($matches[1]);
                if ($header === 'content-type') {
                    $contentType = $matches[2];
                } elseif ($header === 'location') {
                    $location = $matches[2];
                } elseif ($header === 'content-length') {
                    $lengths[$matches[2]] = true;
                } elseif ($header === 'transfer-encoding') {
                    $transferEncoding = $matches[2];
                }
            }
        }

        // Only a 200 body is used; the length checks of another would hide what the server answered.
        if ($status !== 200) {
            return new Response($status, $contentType, $location, '');
        }

        if ($transferEncoding !== null) {
            throw new FetchFailed(sprintf('it answered an HTTP/1.0 request with Transfer-Encoding %s', $transferEncoding));
        }

        $length = $this->length(array_keys($lengths), $maxBytes);
        $body = '';
        while (!feof($stream) && ($length === null || strlen($body) < $length)) {
            $body .= $this->read($stream, $deadline, $timeout, $length === null ? 8192 : max(1, min(8192, $length - strlen($body))));
            if (strlen($body) > $maxBytes) {
                throw new FetchFailed(sprintf('it is larger than %d bytes', $maxBytes));
            }
        }

        // A short body would be cached, and its hash would vouch for it.
        if ($length !== null && strlen($body) < $length) {
            throw new FetchFailed(sprintf('it ended after %d of %d bytes', strlen($body), $length));
        }

        return new Response($status, $contentType, $location, $body);
    }

    /**
     * The length the headers announce, if any.
     *
     * @param list<array-key> $values the distinct values of Content-Length
     * @param positive-int $maxBytes
     */
    private function length(array $values, int $maxBytes): ?int
    {
        if ($values === []) {
            return null;
        }

        $value = (string) $values[0];
        if (count($values) > 1 || preg_match('#^\d{1,18}\z#', $value) !== 1) {
            throw new FetchFailed('its Content-Length is not one number');
        }

        if ((int) $value > $maxBytes) {
            throw new FetchFailed(sprintf('it is larger than %d bytes', $maxBytes));
        }

        return (int) $value;
    }

    /**
     * One header line without its line break; empty at the end of the headers or of the stream.
     *
     * @param resource $stream
     */
    private function line($stream, float $deadline, int $timeout): string
    {
        $line = '';
        while (!feof($stream) && substr($line, -1) !== "\n") {
            $line .= $this->read($stream, $deadline, $timeout, 1);
            if (strlen($line) > self::MAX_HEADER_BYTES) {
                throw new FetchFailed(sprintf('its headers are larger than %d bytes', self::MAX_HEADER_BYTES));
            }
        }

        return rtrim($line, "\r\n");
    }

    /**
     * @param resource $stream
     * @param positive-int $length
     */
    private function read($stream, float $deadline, int $timeout, int $length): string
    {
        $this->limit($stream, $deadline, $timeout);
        $chunk = fread($stream, $length);
        if (stream_get_meta_data($stream)['timed_out']) {
            throw new FetchFailed(sprintf('it took longer than %d seconds', $timeout));
        }

        return is_string($chunk) ? $chunk : '';
    }

    /**
     * Gives the next socket operation what is left of the deadline.
     *
     * @param resource $stream
     */
    private function limit($stream, float $deadline, int $timeout): void
    {
        $left = $deadline - microtime(true);
        if ($left <= 0) {
            throw new FetchFailed(sprintf('it took longer than %d seconds', $timeout));
        }

        stream_set_timeout($stream, (int) $left, (int) (($left - (int) $left) * 1000000));
    }
}
