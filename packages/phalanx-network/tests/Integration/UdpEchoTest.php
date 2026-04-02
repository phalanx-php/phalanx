<?php

declare(strict_types=1);

namespace Phalanx\Network\Tests\Integration;

use PHPUnit\Framework\TestCase;
use React\Datagram\Factory as DatagramFactory;
use React\EventLoop\Loop;
use React\Promise\Deferred;

use function React\Async\await;
use function React\Async\async;

final class UdpEchoTest extends TestCase
{
    public function test_udp_send_and_receive(): void
    {
        $result = await(async(static function (): mixed {
            $factory = new DatagramFactory();

            // bind a UDP server
            $server = await($factory->createServer('127.0.0.1:0'));
            $address = $server->getLocalAddress();
            preg_match('/:(\d+)$/', $address, $matches);
            $port = (int) $matches[1];

            // echo back whatever we receive
            $server->on('message', static function (string $data, string $remote, $server): void {
                $server->send($data, $remote);
            });

            // send from client
            $client = await($factory->createClient("127.0.0.1:$port"));

            $deferred = new Deferred();
            $client->on('message', static function (string $data) use ($deferred): void {
                $deferred->resolve($data);
            });

            $client->send('hello');

            $timer = Loop::addTimer(2.0, static function () use ($deferred): void {
                $deferred->resolve(null);
            });

            $response = await($deferred->promise());
            Loop::cancelTimer($timer);

            $client->close();
            $server->close();

            return $response;
        })());

        $this->assertSame('hello', $result);
    }
}
