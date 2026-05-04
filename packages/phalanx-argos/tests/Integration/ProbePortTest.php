<?php

declare(strict_types=1);

namespace Phalanx\Argos\Tests\Integration;

use Phalanx\Argos\ProbeResult;
use Phalanx\Argos\Task\ProbePort;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Testing\PhalanxTestCase;

final class ProbePortTest extends PhalanxTestCase
{
    public function testDetectsOpenPort(): void
    {
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertNotFalse($listener, "stream_socket_server failed: {$errstr}");

        $address = stream_socket_get_name($listener, false);
        self::assertNotFalse($address);
        $port = (int) substr($address, strrpos($address, ':') + 1);

        $result = $this->scope->run(static function (ExecutionScope $scope) use ($port): ProbeResult {
            $task = new ProbePort('127.0.0.1', $port, timeoutSeconds: 1.0);
            return $task($scope);
        });

        fclose($listener);

        self::assertSame('tcp', $result->method);
        self::assertSame($port, $result->port);
        self::assertTrue($result->reachable);
        self::assertNotNull($result->latencyMs);
    }

    public function testDetectsClosedPort(): void
    {
        $result = $this->scope->run(static function (ExecutionScope $scope): ProbeResult {
            $task = new ProbePort('127.0.0.1', 1, timeoutSeconds: 0.5);
            return $task($scope);
        });

        self::assertSame('tcp', $result->method);
        self::assertSame(1, $result->port);
        self::assertFalse($result->reachable);
        self::assertNull($result->latencyMs);
    }
}
