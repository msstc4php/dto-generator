<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Infrastructure\Remote;

/**
 * GET through PHP's http stream wrapper: no extension beyond what PHP ships, so it runs wherever the generator does.
 */
final class StreamFetcher implements Fetcher
{
    public function get(string $url, int $timeout, int $maxBytes): Response
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'follow_location' => 0,
                'max_redirects' => 1,
                'timeout' => $timeout,
                // The status decides; without this, a 404 would be a warning and no body.
                'ignore_errors' => true,
                'header' => "Accept: application/json, application/yaml;q=0.9, */*;q=0.5\r\nUser-Agent: msstc4php-dto-generator\r\n",
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);

        $error = null;
        set_error_handler(static function (int $level, string $message) use (&$error): bool {
            $error = $message;

            return true;
        });
        try {
            $stream = fopen($url, 'rb', false, $context);
            $body = is_resource($stream) ? stream_get_contents($stream, $maxBytes + 1) : false;
            $meta = is_resource($stream) ? stream_get_meta_data($stream) : [];
            if (is_resource($stream)) {
                fclose($stream);
            }
        } finally {
            restore_error_handler();
        }

        if (!is_string($body)) {
            throw new FetchFailed($error ?? 'no answer');
        }

        if (strlen($body) > $maxBytes) {
            throw new FetchFailed(sprintf('it is larger than %d bytes', $maxBytes));
        }

        return $this->response(is_array($meta['wrapper_data'] ?? null) ? $meta['wrapper_data'] : [], $body);
    }

    /**
     * @param array<array-key, mixed> $headers the raw header lines, the status line first
     */
    private function response(array $headers, string $body): Response
    {
        $status = 0;
        $contentType = null;
        $location = null;
        foreach ($headers as $line) {
            if (!is_string($line)) {
                continue;
            }

            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $matches) === 1) {
                $status = (int) $matches[1];
            } elseif (preg_match('#^([A-Za-z-]+):\s*(.*)$#', $line, $matches) === 1) {
                $name = strtolower($matches[1]);
                if ($name === 'content-type') {
                    $contentType = trim($matches[2]);
                } elseif ($name === 'location') {
                    $location = trim($matches[2]);
                }
            }
        }

        return new Response($status, $contentType, $location, $body);
    }
}
