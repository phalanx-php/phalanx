<?php

declare(strict_types=1);

namespace AegisSwoole\Tests\Fixtures;

use RuntimeException;

/**
 * Spawns bin/http-test-server.php as a child process and exposes its bound
 * port. Tests share one server for the whole battery via start()/stop().
 */
final class HttpTestServerHandle
{
    /** @var resource|null */
    private $process = null;

    /** @var resource|null */
    private $stdin = null;

    /** @var resource|null */
    private $stdout = null;

    /** @var resource|null */
    private $stderr = null;

    private int $port = 0;

    public function start(): void
    {
        if ($this->process !== null) {
            return;
        }
        $script = __DIR__ . '/../../bin/http-test-server.php';
        $autoload = __DIR__ . '/../../vendor/autoload.php';
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open([PHP_BINARY, $script, $autoload], $descriptors, $pipes);
        if (!is_resource($proc)) {
            throw new RuntimeException('HttpTestServerHandle: proc_open failed');
        }
        $this->process = $proc;
        $this->stdin = $pipes[0];
        $this->stdout = $pipes[1];
        $this->stderr = $pipes[2];

        stream_set_blocking($this->stdout, true);
        stream_set_blocking($this->stderr, false);

        $line = fgets($this->stdout);
        if ($line === false || !preg_match('/PORT=(\d+)/', $line, $m)) {
            $stderr = stream_get_contents($this->stderr) ?: '';
            $this->stop();
            throw new RuntimeException("HttpTestServerHandle: did not see PORT= line. stderr: {$stderr}");
        }
        $this->port = (int) $m[1];
    }

    public function port(): int
    {
        return $this->port;
    }

    public function stop(): void
    {
        if ($this->process === null) {
            return;
        }
        if (is_resource($this->stdin)) {
            @fclose($this->stdin);
        }
        if (is_resource($this->stdout)) {
            @fclose($this->stdout);
        }
        if (is_resource($this->stderr)) {
            @fclose($this->stderr);
        }
        proc_terminate($this->process, SIGTERM);
        // Best-effort; OpenSwoole HTTP\Server may need SIGKILL to exit cleanly.
        $deadline = microtime(true) + 1.5;
        while (microtime(true) < $deadline) {
            $status = proc_get_status($this->process);
            if (!$status['running']) {
                break;
            }
            usleep(50_000);
        }
        $status = proc_get_status($this->process);
        if ($status['running']) {
            proc_terminate($this->process, SIGKILL);
        }
        proc_close($this->process);
        $this->process = null;
        $this->stdin = $this->stdout = $this->stderr = null;
    }
}
