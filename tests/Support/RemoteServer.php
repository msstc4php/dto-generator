<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Support;

use RuntimeException;

/**
 * PHP's built-in server on a free local port, serving tests/Fixtures/Remote.
 */
final class RemoteServer
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

    public static function start(): self
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        if ($socket === false) {
            throw new RuntimeException('No free port.');
        }

        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        $port = (int) substr($name, (int) strrpos($name, ':') + 1);
        $root = __DIR__ . '/../Fixtures/Remote';
        $process = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $root, $root . '/router.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('The server did not start.');
        }

        $server = new self($process, $port);
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $connection = @fsockopen('127.0.0.1', $port);
            if (is_resource($connection)) {
                fclose($connection);

                return $server;
            }

            usleep(20000);
        }

        $server->stop();

        throw new RuntimeException('The server did not answer.');
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
