<?php

declare(strict_types=1);

namespace Phalanx\WebSocket\Tests\Integration;

use Phalanx\Scope\ExecutionScope;
use Phalanx\Testing\PhalanxTestCase;
use Phalanx\WebSocket\Client;
use Phalanx\WebSocket\Tests\Support\Rfc6455TestServer;
use Phalanx\WebSocket\WebSocket;
use Phalanx\WebSocket\CloseCode;
use PHPUnit\Framework\Attributes\Test;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\Socket;

/**
 * Two writer coroutines call Client->send() simultaneously. The on-the-wire
 * bytes must decode into two complete, non-interleaved RFC6455 frames in some
 * order; that contract is what the writer-side Channel serialisation
 * guarantees and what real-world peers depend on.
 */
final class ClientConcurrentSendTest extends PhalanxTestCase
{
    #[Test]
    public function concurrentSendsProduceNonInterleavedFrames(): void
    {
        $testApp = $this->testApp([], \Phalanx\WebSocket\WebSocket::services());

        $this->scope->run(static function (ExecutionScope $_scope) use ($testApp): void {
            $testApp->application->startup();
            $scope = $testApp->application->createScope();

            try {
                $framesChannel = new Channel(4);
                $server = Rfc6455TestServer::start(
                    $scope,
                    static function (ExecutionScope $serverScope, Socket $conn) use ($framesChannel): void {
                        Rfc6455TestServer::pushClientTextFrames($serverScope, $conn, 2, $framesChannel);
                    },
                );

                $client = $scope->service(\Phalanx\WebSocket\Client::class);
                $connection = $client->connect($scope, $server->url('/echo'));

                try {
                    $payloadA = str_repeat('A', 32);
                    $payloadB = str_repeat('B', 32);

                    $writers = new Channel(2);
                    $scope->go(static function () use ($connection, $payloadA, $writers): void {
                        try {
                            $connection->sendText($payloadA);
                        } finally {
                            $writers->push(true);
                        }
                    });
                    $scope->go(static function () use ($connection, $payloadB, $writers): void {
                        try {
                            $connection->sendText($payloadB);
                        } finally {
                            $writers->push(true);
                        }
                    });
                    self::assertTrue($writers->pop(2), 'first writer coroutine never finished');
                    self::assertTrue($writers->pop(2), 'second writer coroutine never finished');

                    $first = $framesChannel->pop(3);
                    $second = $framesChannel->pop(3);
                    self::assertNotFalse($first, 'first frame never decoded server-side');
                    self::assertNotFalse($second, 'second frame never decoded server-side');

                    $received = [$first, $second];
                    sort($received);
                    $expected = [$payloadA, $payloadB];
                    sort($expected);
                    self::assertSame($expected, $received, 'decoded frames must equal the two sent payloads');
                } finally {
                    $connection->close(\Phalanx\WebSocket\CloseCode::Normal, 'test_done');
                    self::assertTrue($server->awaitDone(), 'server fixture did not signal done');
                }
            } finally {
                $scope->dispose();
            }
        });
    }
}
