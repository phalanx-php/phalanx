<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\System;

use Phalanx\Scope\ExecutionScope;
use Phalanx\System\TcpClient;
use Phalanx\Testing\PhalanxTestCase;

final class TcpClientTest extends PhalanxTestCase
{
    public function testConnectToBoundLocalhostPort(): void
    {
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertNotFalse($listener, "stream_socket_server failed: {$errstr}");

        $address = stream_socket_get_name($listener, false);
        self::assertNotFalse($address);
        $port = (int) substr($address, strrpos($address, ':') + 1);

        $connected = $this->scope->run(static function (ExecutionScope $scope) use ($port): bool {
            $client = new TcpClient();
            $result = $client->connect($scope, '127.0.0.1', $port, 1.0);
            $client->close();
            return $result;
        });

        fclose($listener);

        self::assertTrue($connected);
    }

    public function testConnectToClosedPortReturnsFalse(): void
    {
        $connected = $this->scope->run(static function (ExecutionScope $scope): bool {
            $client = new TcpClient();
            $result = $client->connect($scope, '127.0.0.1', 1, 0.5);
            $client->close();
            return $result;
        });

        self::assertFalse($connected);
    }
}
