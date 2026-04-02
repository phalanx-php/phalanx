<?php

declare(strict_types=1);

namespace Phalanx\Network\Tests\Integration;

use PHPUnit\Framework\TestCase;
use React\Socket\SocketServer;

use function React\Async\await;
use function React\Async\async;

final class ProbePortTest extends TestCase
{
    public function test_detects_open_port(): void
    {
        $server = new SocketServer('127.0.0.1:0');
        $address = $server->getAddress();
        preg_match('/\d+$/', $address, $matches);
        $port = (int) $matches[0];

        $result = await(async(static function () use ($port): mixed {
            $connector = new \React\Socket\Connector();

            try {
                $conn = await($connector->connect("tcp://127.0.0.1:$port"));
                $conn->close();
                return true;
            } catch (\RuntimeException) {
                return false;
            }
        })());

        $server->close();
        $this->assertTrue($result);
    }

    public function test_detects_closed_port(): void
    {
        $result = await(async(static function (): mixed {
            $connector = new \React\Socket\Connector([
                'timeout' => 1,
            ]);

            try {
                $conn = await($connector->connect('tcp://127.0.0.1:19999'));
                $conn->close();
                return true;
            } catch (\RuntimeException) {
                return false;
            }
        })());

        $this->assertFalse($result);
    }
}
