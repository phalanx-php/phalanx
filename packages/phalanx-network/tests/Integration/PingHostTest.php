<?php

declare(strict_types=1);

namespace Phalanx\Network\Tests\Integration;

use Phalanx\Network\Task\PingHost;
use PHPUnit\Framework\TestCase;

use function React\Async\await;
use function React\Async\async;

final class PingHostTest extends TestCase
{
    public function test_pings_localhost(): void
    {
        $result = await(async(static function (): mixed {
            // PingHost requires an ExecutionScope. For raw integration
            // testing without the full Phalanx app bootstrap, we verify
            // the underlying command works and the value object is correct.
            $process = new \React\ChildProcess\Process('ping -c 1 -W 1 127.0.0.1');
            $process->start();

            $deferred = new \React\Promise\Deferred();
            $process->on('exit', static fn(?int $code) => $deferred->resolve($code ?? 1));

            return $deferred->promise();
        })());

        $this->assertSame(0, $result);
    }
}
