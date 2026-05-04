<?php

declare(strict_types=1);

namespace Phalanx\Argos\Tests\Integration;

use Closure;
use Phalanx\Argos\NetworkConfig;
use Phalanx\Argos\ProbeResult;
use Phalanx\Argos\Task\PingHost;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Service\Services;
use Phalanx\Testing\PhalanxTestCase;

final class PingHostTest extends PhalanxTestCase
{
    public function testPingsLocalhost(): void
    {
        $result = $this->scope->run(static function (ExecutionScope $scope): ProbeResult {
            $task = new PingHost('127.0.0.1', timeoutSeconds: 1.0);

            return $task($scope);
        });

        self::assertSame('127.0.0.1', $result->ip);
        self::assertTrue($result->reachable);
        self::assertSame('icmp', $result->method);
        self::assertNotNull($result->latencyMs);
    }

    public function testReportsUnreachableForBogusAddress(): void
    {
        $result = $this->scope->run(static function (ExecutionScope $scope): ProbeResult {
            $task = new PingHost('203.0.113.1', timeoutSeconds: 1.0);

            return $task($scope);
        });

        self::assertSame('203.0.113.1', $result->ip);
        self::assertFalse($result->reachable);
        self::assertNull($result->latencyMs);
        self::assertSame('icmp', $result->method);
    }

    protected function phalanxServices(): ?Closure
    {
        return static function (Services $services, array $context): void {
            $services->config(NetworkConfig::class, static fn(array $ctx): NetworkConfig => new NetworkConfig(
                pingBinary: (string) ($ctx['NETWORK_PING_BINARY'] ?? 'ping'),
            ));
        };
    }
}
