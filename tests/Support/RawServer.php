<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Support;

use RuntimeException;

/**
 * A server on a free local port that answers one connection with the given bytes, whatever was asked.
 */
final class RawServer
{
    /** @var resource */
    private $process;

    private int $port;

    /**
     * @param resource $process
     */
    private function __construct($process, int $port)
    {
        $this->process = $process;
        $this->port = $port;
    }

    /**
     * @param int $silence seconds to wait before answering
     */
    public static function answering(string $answer, int $silence = 0): self
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        if ($socket === false) {
            throw new RuntimeException('No free port.');
        }

        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        $port = (int) substr($name, (int) strrpos($name, ':') + 1);
        $ready = sys_get_temp_dir() . '/raw-server-' . bin2hex(random_bytes(4));
        $code = sprintf(
            '$s = stream_socket_server("tcp://127.0.0.1:%d"); touch(%s); $c = stream_socket_accept($s, 10); sleep(%d); fread($c, 4096); fwrite($c, base64_decode("%s")); fclose($c);',
            $port,
            var_export($ready, true),
            $silence,
            base64_encode($answer),
        );
        $process = proc_open([PHP_BINARY, '-r', $code], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('The server did not start.');
        }

        // Probing would use up the one connection, so the server says when it listens.
        for ($wait = 0; !is_file($ready); $wait++) {
            if ($wait > 250) {
                throw new RuntimeException('The server did not listen.');
            }

            usleep(20000);
        }

        unlink($ready);

        return new self($process, $port);
    }

    public function url(string $path): string
    {
        return 'http://127.0.0.1:' . $this->port . $path;
    }

    public function stop(): void
    {
        proc_terminate($this->process);
        proc_close($this->process);
    }
}
