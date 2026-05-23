<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\System;

use OpenSwoole\Coroutine;
use Phalanx\Scope\ExecutionScope;
use Phalanx\System\UdpSocket;
use Phalanx\Testing\PhalanxTestCase;

final class UdpSocketTest extends PhalanxTestCase
{
    public function testSendAndReceiveFromUdpEchoServer(): void
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

        $response = $this->scope->run(static function (ExecutionScope $scope) use ($server, $port): ?string {
            // Run an echo loop on a sibling coroutine so it can receive the
            // client's send() while the client coroutine is suspended on recv().
            Coroutine::create(static function () use ($server): void {
                $deadline = microtime(true) + 1.0;
                while (microtime(true) < $deadline) {
                    $remote = '';
                    $payload = @stream_socket_recvfrom($server, 1024, 0, $remote);
                    if ($payload !== false && $payload !== '' && $remote !== '') {
                        @stream_socket_sendto($server, "pong:{$payload}", 0, $remote);
                        return;
                    }
                    Coroutine::usleep(10_000);
                }
            });

            $client = new UdpSocket();
            $client->connect($scope, '127.0.0.1', $port, 0.5);
            $client->send($scope, 'ping', 0.5);
            $recv = $client->recv($scope, 0.5);
            $client->close();
            return $recv;
        });

        fclose($server);

        self::assertSame('pong:ping', $response);
    }

    public function testSendWithoutResponseDoesNotBlock(): void
    {
        $this->scope->run(static function (ExecutionScope $scope): void {
            $client = new UdpSocket();
            $client->connect($scope, '127.0.0.1', 1, 0.5);
            $written = $client->send($scope, 'no-listener', 0.2);
            $client->close();

            self::assertGreaterThan(0, $written);
        });
    }
}
