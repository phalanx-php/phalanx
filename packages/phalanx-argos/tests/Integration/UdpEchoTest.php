<?php

declare(strict_types=1);

namespace Phalanx\Argos\Tests\Integration;

use OpenSwoole\Coroutine;
use Phalanx\Argos\ProbeResult;
use Phalanx\Argos\Task\ProbeUdp;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Testing\PhalanxTestCase;

final class UdpEchoTest extends PhalanxTestCase
{
    public function testProbeUdpAgainstEchoServer(): void
    {
        $server = stream_socket_server(
            'udp://127.0.0.1:0',
            $errno,
            $errstr,
            STREAM_SERVER_BIND,
        );
        self::assertNotFalse($server, "stream_socket_server failed: {$errstr}");
        stream_set_blocking($server, false);

        $address = stream_socket_get_name($server, false);
        self::assertNotFalse($address);
        $port = (int) substr($address, strrpos($address, ':') + 1);

        $result = $this->scope->run(static function (ExecutionScope $scope) use ($server, $port): ProbeResult {
            Coroutine::create(static function () use ($server): void {
                $deadline = microtime(true) + 1.0;
                while (microtime(true) < $deadline) {
                    $remote = '';
                    $payload = @stream_socket_recvfrom($server, 1024, 0, $remote);
                    if ($payload !== false && $payload !== '' && $remote !== '') {
                        @stream_socket_sendto($server, "echo:{$payload}", 0, $remote);
                        return;
                    }
                    Coroutine::usleep(10_000);
                }
            });

            $task = new ProbeUdp('127.0.0.1', $port, payload: 'hello', timeoutSeconds: 1.0);
            return $task($scope);
        });

        fclose($server);

        self::assertSame('udp', $result->method);
        self::assertSame($port, $result->port);
        self::assertTrue($result->reachable);
        self::assertSame('echo:hello', $result->responseData);
    }
}
