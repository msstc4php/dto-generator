<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Support;

use RuntimeException;

/**
 * An HTTP proxy on a free local port that logs the head of every request it gets. It tunnels CONNECT to the named
 * address and answers any other request itself with a small YAML document, or refuses everything with 407.
 */
final class ProxyServer
{
    private const SCRIPT = <<<'PHP'
        [$port, $log, $deny] = [(int) $argv[1], $argv[2], $argv[3] === '1'];
        $server = stream_socket_server('tcp://127.0.0.1:' . $port);
        file_put_contents($log . '.part', $server === false ? 'failed' : 'listening');
        rename($log . '.part', $log . '.ready');
        while ($client = @stream_socket_accept($server, 30)) {
            $head = '';
            while (!feof($client) && strpos($head, "\r\n\r\n") === false) {
                $head .= fread($client, 1);
            }
            file_put_contents($log, $head, FILE_APPEND);
            if ($deny) {
                fwrite($client, "HTTP/1.1 407 Proxy Authentication Required\r\nProxy-Authenticate: Basic\r\nContent-Length: 0\r\n\r\n");
            } elseif (preg_match('#^CONNECT (\S+) #', $head, $m) === 1) {
                $upstream = stream_socket_client('tcp://' . $m[1], $errno, $error, 5);
                fwrite($client, "HTTP/1.1 200 Connection established\r\n\r\n");
                $streams = [$client, $upstream];
                while (true) {
                    $read = $streams;
                    $write = $except = null;
                    if (stream_select($read, $write, $except, 10) < 1) {
                        break;
                    }
                    foreach ($read as $from) {
                        $data = fread($from, 8192);
                        if ($data === '' || $data === false) {
                            break 2;
                        }
                        fwrite($from === $client ? $upstream : $client, $data);
                    }
                }
                fclose($upstream);
            } else {
                fwrite($client, "HTTP/1.0 200 OK\r\nContent-Type: application/yaml\r\nContent-Length: 5\r\n\r\nA: {}");
            }
            fclose($client);
        }
        PHP;

    /** @var resource */
    private $process;

    private int $port;

    private string $log;

    /**
     * @param resource $process
     */
    private function __construct($process, int $port, string $log)
    {
        $this->process = $process;
        $this->port = $port;
        $this->log = $log;
    }

    public static function start(bool $deny = false): self
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        if ($socket === false) {
            throw new RuntimeException('No free port.');
        }

        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        $port = (int) substr($name, (int) strrpos($name, ':') + 1);
        $log = sys_get_temp_dir() . '/proxy-' . bin2hex(random_bytes(4)) . '.log';
        $process = proc_open([PHP_BINARY, '-r', self::SCRIPT, '--', (string) $port, $log, $deny ? '1' : '0'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('The proxy did not start.');
        }

        for ($wait = 0; !is_file($log . '.ready'); $wait++) {
            if ($wait > 100) {
                throw new RuntimeException('The proxy did not listen.');
            }

            usleep(20000);
        }

        $proxy = new self($process, $port, $log);
        if (file_get_contents($log . '.ready') !== 'listening') {
            $proxy->stop();

            throw new RuntimeException('The proxy could not listen on its port.');
        }

        return $proxy;
    }

    public function port(): int
    {
        return $this->port;
    }

    /**
     * The heads of the requests so far.
     */
    public function log(): string
    {
        return is_file($this->log) ? (string) file_get_contents($this->log) : '';
    }

    public function stop(): void
    {
        proc_terminate($this->process);
        proc_close($this->process);
        foreach ([$this->log, $this->log . '.ready'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
}
