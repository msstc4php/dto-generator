<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Support;

use RuntimeException;

/**
 * A TLS server on 127.0.0.1 with a self-signed certificate for the given subjectAltName. It logs the head of every
 * request and answers each with a small YAML document.
 */
final class TlsServer
{
    private const SCRIPT = <<<'PHP'
        [$port, $dir] = [(int) $argv[1], $argv[2]];
        $context = stream_context_create(['ssl' => ['local_cert' => $dir . '/cert.pem', 'local_pk' => $dir . '/key.pem']]);
        $server = stream_socket_server('tcp://127.0.0.1:' . $port, $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
        file_put_contents($dir . '/ready.part', $server === false ? 'failed' : 'listening');
        rename($dir . '/ready.part', $dir . '/ready');
        while ($client = @stream_socket_accept($server, 30)) {
            stream_set_timeout($client, 5);
            if (@stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_SERVER) !== true) {
                fclose($client);
                continue;
            }
            $head = '';
            while (!feof($client) && strpos($head, "\r\n\r\n") === false) {
                $head .= fread($client, 1);
            }
            file_put_contents($dir . '/log', $head, FILE_APPEND);
            fwrite($client, "HTTP/1.0 200 OK\r\nContent-Type: application/yaml\r\nContent-Length: 5\r\n\r\nA: {}");
            fclose($client);
        }
        PHP;

    /** @var resource */
    private $process;

    private int $port;

    private string $dir;

    /**
     * @param resource $process
     */
    private function __construct($process, int $port, string $dir)
    {
        $this->process = $process;
        $this->port = $port;
        $this->dir = $dir;
    }

    /**
     * @param string $subjectAltName such as "DNS:localhost" or "IP:127.0.0.1"
     *
     * @return self|null null without an openssl command that makes the certificate
     */
    public static function start(string $subjectAltName): ?self
    {
        $openssl = trim((string) shell_exec('command -v openssl'));
        if ($openssl === '') {
            return null;
        }

        $dir = sys_get_temp_dir() . '/tls-' . bin2hex(random_bytes(4));
        mkdir($dir);
        exec(sprintf(
            '%s req -x509 -newkey rsa:2048 -nodes -subj /CN=dto-generator-test -addext %s -days 1 -keyout %s -out %s 2>/dev/null',
            escapeshellarg($openssl),
            escapeshellarg('subjectAltName=' . $subjectAltName),
            escapeshellarg($dir . '/key.pem'),
            escapeshellarg($dir . '/cert.pem'),
        ));
        // An openssl without -addext (an old LibreSSL) makes no certificate: nothing to test with.
        if (!is_file($dir . '/cert.pem')) {
            foreach ((array) glob($dir . '/*') as $file) {
                unlink((string) $file);
            }

            rmdir($dir);

            return null;
        }

        $socket = stream_socket_server('tcp://127.0.0.1:0');
        if ($socket === false) {
            throw new RuntimeException('No free port.');
        }

        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        $port = (int) substr($name, (int) strrpos($name, ':') + 1);
        $process = proc_open([PHP_BINARY, '-r', self::SCRIPT, '--', (string) $port, $dir], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('The TLS server did not start.');
        }

        for ($wait = 0; !is_file($dir . '/ready'); $wait++) {
            if ($wait > 100) {
                throw new RuntimeException('The TLS server did not listen.');
            }

            usleep(20000);
        }

        $server = new self($process, $port, $dir);
        if (file_get_contents($dir . '/ready') !== 'listening') {
            $server->stop();

            throw new RuntimeException('The TLS server could not listen on its port.');
        }

        return $server;
    }

    public function port(): int
    {
        return $this->port;
    }

    public function certificate(): string
    {
        return $this->dir . '/cert.pem';
    }

    /**
     * The heads of the requests so far, as the server decrypted them.
     */
    public function log(): string
    {
        return is_file($this->dir . '/log') ? (string) file_get_contents($this->dir . '/log') : '';
    }

    public function stop(): void
    {
        proc_terminate($this->process);
        proc_close($this->process);
        foreach ((array) glob($this->dir . '/*') as $file) {
            unlink((string) $file);
        }

        rmdir($this->dir);
    }
}
