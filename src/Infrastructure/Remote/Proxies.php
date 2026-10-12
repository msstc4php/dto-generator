<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Infrastructure\Remote;

use Closure;

/**
 * The proxies of the environment, read as curl reads them (spec F3 §3): `https_proxy`/`HTTPS_PROXY` for https,
 * `http_proxy` alone for http, since a CGI server sets `HTTP_PROXY` from a request header (httpoxy), and
 * `no_proxy`/`NO_PROXY` for the hosts reached directly.
 */
final class Proxies
{
    private const DEFAULT_PORT = 1080;

    /** @var Closure(string): ?string */
    private Closure $environment;

    /**
     * @param Closure(string): ?string $environment
     */
    private function __construct(Closure $environment)
    {
        $this->environment = $environment;
    }

    /**
     * @param (Closure(string): ?string)|null $environment the value of a variable, null when it is not set; the process
     *                                                     environment by default
     */
    public static function fromEnvironment(?Closure $environment = null): self
    {
        return new self($environment ?? static fn (string $name): ?string => Environment::variable($name));
    }

    /**
     * @param string $host as parse_url() gives it, an IPv6 address in brackets
     *
     * @throws FetchFailed when the variable that applies holds no usable proxy
     */
    public function for(string $scheme, string $host, int $port): ?Proxy
    {
        [$name, $value] = $this->variable($scheme === 'https' ? ['https_proxy', 'HTTPS_PROXY'] : ['http_proxy']);
        if ($name === null || $this->bypasses(strtolower($host), $port)) {
            return null;
        }

        return $this->proxy($name, $value);
    }

    /**
     * @param list<string> $names in order of preference
     *
     * @return array{?string, string} the name and trimmed value of the first variable that is set and not blank
     */
    private function variable(array $names): array
    {
        foreach ($names as $name) {
            $value = trim((string) ($this->environment)($name));
            if ($value !== '') {
                return [$name, $value];
            }
        }

        return [null, ''];
    }

    private function proxy(string $name, string $value): Proxy
    {
        $url = preg_match('#^[A-Za-z][A-Za-z0-9+.\-]*://#', $value) === 1 ? $value : 'http://' . $value;
        $parts = preg_match('#[\x00-\x20\x7F]#', $url) === 1 ? false : parse_url($url);
        if (!is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'http' || ($parts['host'] ?? '') === '') {
            throw new FetchFailed(sprintf('the proxy in %s must be an http:// URL with a host', $name));
        }

        $authorization = null;
        if (isset($parts['user'])) {
            $authorization = 'Basic ' . base64_encode(rawurldecode($parts['user']) . ':' . rawurldecode($parts['pass'] ?? ''));
        }

        return new Proxy(strtolower($parts['host']), $parts['port'] ?? self::DEFAULT_PORT, $authorization);
    }

    private function bypasses(string $host, int $port): bool
    {
        [, $list] = $this->variable(['no_proxy', 'NO_PROXY']);
        $host = rtrim(trim($host, '[]'), '.');
        // An address has no parent domain, and has more than one spelling: 0:0:0:0:0:0:0:1 is ::1.
        $address = filter_var($host, FILTER_VALIDATE_IP) !== false ? inet_pton($host) : false;
        $entries = preg_split('#[\s,]+#', strtolower($list), -1, PREG_SPLIT_NO_EMPTY);
        foreach (is_array($entries) ? $entries : [] as $entry) {
            if ($entry === '*') {
                return true;
            }

            [$entryHost, $entryPort] = $this->entry($entry);
            if ($entryPort !== null && $entryPort !== $port) {
                continue;
            }

            if ($address !== false) {
                if (filter_var($entryHost, FILTER_VALIDATE_IP) !== false && inet_pton($entryHost) === $address) {
                    return true;
                }
            } elseif ($host === $entryHost || substr($host, -strlen($entryHost) - 1) === '.' . $entryHost) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{string, ?int} the host without brackets or dots around it, and the port if the entry names one
     */
    private function entry(string $entry): array
    {
        if (preg_match('#^\[([^\]]+)\](?::(\d+))?\z#', $entry, $matches) === 1) {
            return [$matches[1], isset($matches[2]) ? (int) $matches[2] : null];
        }

        // One colon is a port; more make a bare IPv6 address.
        if (preg_match('#^([^:]+):(\d+)\z#', $entry, $matches) === 1) {
            return [trim($matches[1], '.'), (int) $matches[2]];
        }

        return [trim($entry, '.'), null];
    }
}
