<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Infrastructure\Remote;

/**
 * One HTTP/1.0 GET over a socket: PHP's http wrapper bounds neither the time of a whole response (its timeout is per
 * read) nor the size of its headers, and both are the server's to choose. HTTP/1.0 keeps the body free of chunks.
 */
final class StreamFetcher implements Fetcher
{
    private const MAX_HEADER_BYTES = 65536;

    public function get(string $url, int $timeout, int $maxBytes): Response
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new FetchFailed(sprintf('"%s" is no absolute URL', $url));
        }

        $deadline = microtime(true) + $timeout;
        $secure = strtolower($parts['scheme']) === 'https';
        $port = $parts['port'] ?? ($secure ? 443 : 80);
        $name = trim($parts['host'], '[]');
        $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $name, 'SNI_enabled' => true]]);
        // A failed TLS handshake explains itself in a warning only.
        $warning = null;
        set_error_handler(static function (int $level, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });
        try {
            $stream = stream_socket_client(($secure ? 'tls' : 'tcp') . '://' . $parts['host'] . ':' . $port, $errno, $error, $timeout, STREAM_CLIENT_CONNECT, $context);
        } finally {
            restore_error_handler();
        }

        if (!is_resource($stream)) {
            throw new FetchFailed($warning ?? (($error ?? '') === '' ? 'the connection failed' : (string) $error));
        }

        try {
            $target = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
            $host = $parts['host'] . (isset($parts['port']) ? ':' . $port : '');
            fwrite($stream, "GET {$target} HTTP/1.0\r\nHost: {$host}\r\nAccept: application/json, application/yaml;q=0.9, */*;q=0.5\r\nUser-Agent: msstc4php-dto-generator\r\nConnection: close\r\n\r\n");

            return $this->response($stream, $deadline, $timeout, $maxBytes);
        } finally {
            fclose($stream);
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
        $headerBytes = 0;
        for ($first = true; ($line = $this->line($stream, $deadline, $timeout)) !== ''; $first = false) {
            $headerBytes += strlen($line);
            if ($headerBytes > self::MAX_HEADER_BYTES) {
                throw new FetchFailed(sprintf('its headers are larger than %d bytes', self::MAX_HEADER_BYTES));
            }

            if ($first) {
                if (preg_match('#^HTTP/\d\.\d (\d{3})#', $line, $matches) !== 1) {
                    throw new FetchFailed('the server did not answer in HTTP');
                }

                $status = (int) $matches[1];
            } elseif (preg_match('#^([A-Za-z0-9-]+):\s*(.*)$#', $line, $matches) === 1) {
                $header = strtolower($matches[1]);
                if ($header === 'content-type') {
                    $contentType = $matches[2];
                } elseif ($header === 'location') {
                    $location = $matches[2];
                }
            }
        }

        $body = '';
        while (!feof($stream)) {
            $body .= $this->read($stream, $deadline, $timeout, 8192);
            if (strlen($body) > $maxBytes) {
                throw new FetchFailed(sprintf('it is larger than %d bytes', $maxBytes));
            }
        }

        return new Response($status, $contentType, $location, $body);
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
        $left = $deadline - microtime(true);
        if ($left <= 0) {
            throw new FetchFailed(sprintf('it took longer than %d seconds', $timeout));
        }

        stream_set_timeout($stream, (int) $left, (int) (($left - (int) $left) * 1000000));
        $chunk = fread($stream, $length);
        if (stream_get_meta_data($stream)['timed_out']) {
            throw new FetchFailed(sprintf('it took longer than %d seconds', $timeout));
        }

        return is_string($chunk) ? $chunk : '';
    }
}
