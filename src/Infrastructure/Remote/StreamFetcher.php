<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Infrastructure\Remote;

use MSSTC4PHP\DtoGenerator\Application\Config\RemoteHeader;
use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Url;

/**
 * One HTTP/1.0 GET over a socket, directly or through the proxy of the environment: PHP's http wrapper bounds neither
 * the time of a whole response (its timeout is per read) nor the size of its headers, and both are the server's to
 * choose. HTTP/1.0 keeps the body free of chunks.
 */
final class StreamFetcher implements Fetcher
{
    private const ACCEPT = 'application/json, application/yaml;q=0.9, */*;q=0.5';

    private ?Proxies $proxies;

    private ?string $caFile;

    /**
     * @param Proxies|null $proxies those of the process environment by default
     * @param string|null $caFile internal, for tests: the certificates to trust instead of the system's
     */
    public function __construct(?Proxies $proxies = null, ?string $caFile = null)
    {
        $this->proxies = $proxies;
        $this->caFile = $caFile;
    }

    public function get(string $url, int $timeout, int $maxBytes, array $headers = []): Response
    {
        // Only the spelling the allow list checked, which also keeps line breaks out of the request.
        $parts = $this->isNormalized($url) ? parse_url($url) : false;
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new FetchFailed(sprintf('"%s" is no normalized http(s) URL', $url));
        }

        $fields = $this->fields($headers);
        $secure = $parts['scheme'] === 'https';
        $port = $parts['port'] ?? ($secure ? 443 : 80);
        $proxy = ($this->proxies ?? Proxies::fromEnvironment())->for($parts['scheme'], $parts['host'], $port);
        $connection = $proxy instanceof Proxy
            ? Connection::open($proxy->host(), $proxy->port(), $parts['host'], $this->caFile, $timeout, sprintf('the proxy at %s: ', $proxy->label()))
            : Connection::open($parts['host'], $port, $parts['host'], $this->caFile, $timeout, '');
        try {
            if ($proxy instanceof Proxy && $secure) {
                $this->tunnel($connection, $parts['host'] . ':' . $port, $proxy);
            }

            if ($secure) {
                $connection->secure();
            }

            $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
            $host = $parts['host'] . (isset($parts['port']) ? ':' . $port : '');
            // A proxy forwards plain http by the absolute URL (RFC 9112 §3.2.2).
            $target = $proxy instanceof Proxy && !$secure ? 'http://' . $host . $path : $path;
            $request = "GET {$target} HTTP/1.0\r\nHost: {$host}\r\n" . $fields;
            if ($proxy instanceof Proxy && !$secure && $proxy->authorization() !== null) {
                $request .= 'Proxy-Authorization: ' . $proxy->authorization() . "\r\n";
            }

            $connection->write($request . "Connection: close\r\n\r\n");

            return $this->response($connection, $maxBytes);
        } finally {
            $connection->close();
        }
    }

    /**
     * Accept and User-Agent unless the given headers replace them, then the given ones.
     *
     * @param array<string, string> $headers
     */
    private function fields(array $headers): string
    {
        $lines = ['accept' => 'Accept: ' . self::ACCEPT, 'user-agent' => 'User-Agent: msstc4php-dto-generator'];
        foreach ($headers as $name => $value) {
            // The config checked them already; the request must not depend on that.
            if (preg_match('/^' . RemoteHeader::TOKEN . '\z/', $name) !== 1 || RemoteHeader::isReserved($name) || RemoteHeader::hasControlCharacters($value)) {
                throw new FetchFailed(sprintf('the header %s cannot be sent', preg_replace('#[^\x21-\x7E]#', '?', $name)));
            }

            $lines[strtolower($name)] = $name . ': ' . $value;
        }

        return implode("\r\n", $lines) . "\r\n";
    }

    /**
     * Asks the proxy for a tunnel to the server (RFC 9110 §9.3.6); TLS to the server then runs through it.
     */
    private function tunnel(Connection $connection, string $authority, Proxy $proxy): void
    {
        $request = "CONNECT {$authority} HTTP/1.1\r\nHost: {$authority}\r\n";
        if ($proxy->authorization() !== null) {
            $request .= 'Proxy-Authorization: ' . $proxy->authorization() . "\r\n";
        }

        $connection->write($request . "\r\n");
        $status = $connection->head()[0];
        if ($status === 407) {
            throw new FetchFailed(sprintf('the proxy at %s requires authentication (407)', $proxy->label()));
        }

        if ($status < 200 || $status > 299) {
            throw new FetchFailed(sprintf('the proxy at %s answered %d', $proxy->label(), $status));
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
     * @param positive-int $maxBytes
     */
    private function response(Connection $connection, int $maxBytes): Response
    {
        [$status, $headers] = $connection->head();
        $contentType = null;
        $location = null;
        $lengths = [];
        $transferEncoding = null;
        foreach ($headers as [$header, $value]) {
            if ($header === 'content-type') {
                $contentType = $value;
            } elseif ($header === 'location') {
                $location = $value;
            } elseif ($header === 'content-length') {
                $lengths[$value] = true;
            } elseif ($header === 'transfer-encoding') {
                $transferEncoding = $value;
            }
        }

        // Only a 200 body is used; the length checks of another would hide what the server answered.
        if ($status !== 200) {
            return new Response($status, $contentType, $location, '');
        }

        if ($transferEncoding !== null) {
            throw new FetchFailed(sprintf('it answered an HTTP/1.0 request with Transfer-Encoding %s', $transferEncoding));
        }

        return new Response($status, $contentType, $location, $connection->body($this->length(array_keys($lengths), $maxBytes), $maxBytes));
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
}
