<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\System;

use Phalanx\Scope\ExecutionScope;
use Phalanx\System\TcpClient;
use Phalanx\Tests\Support\CoroutineTestCase;

final class TcpClientTest extends CoroutineTestCase
{
    public function testConnectToBoundLocalhostPort(): void
    {
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertNotFalse($listener, "stream_socket_server failed: {$errstr}");

        $address = stream_socket_get_name($listener, false);
        self::assertNotFalse($address);
        $port = (int) substr($address, strrpos($address, ':') + 1);

        $connected = null;

        $this->runScoped(static function (ExecutionScope $scope) use ($port, &$connected): void {
            $client = new TcpClient();
            $connected = $client->connect($scope, '127.0.0.1', $port, 1.0);
            $client->close();
        });

        fclose($listener);

        self::assertTrue($connected);
    }

    public function testConnectToClosedPortReturnsFalse(): void
    {
        $connected = null;

        $this->runScoped(static function (ExecutionScope $scope) use (&$connected): void {
            $client = new TcpClient();
            $connected = $client->connect($scope, '127.0.0.1', 1, 0.5);
            $client->close();
        });

        self::assertFalse($connected);
    }
}
