<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\System;

use Phalanx\Scope\ExecutionScope;
use Phalanx\System\HttpClient;
use Phalanx\System\HttpException;
use Phalanx\System\HttpRequest;
use Phalanx\System\TlsOptions;
use Phalanx\Tests\Support\CoroutineTestCase;

/**
 * HTTP/2 round-trip integration coverage runs against the live LLM
 * APIs via the Athena demos (the canonical end-to-end harness for an
 * outbound HTTP client). At the unit level we exercise:
 *   - construction with TLS on/off
 *   - the connect-failure error path against an unreachable target
 * so that the seam is exercised without depending on a live HTTP/2
 * server fixture (OpenSwoole's coroutine HTTP/2 server surface is not
 * suited to ephemeral test fixtures the way the HTTP/1.1 server is).
 */
final class HttpClientConstructionTest extends CoroutineTestCase
{
    public function testTlsFlagPropagates(): void
    {
        $tls = new HttpClient('api.anthropic.com', 443, tls: true);
        $plain = new HttpClient('localhost', 8080, tls: false);

        self::assertTrue($tls->tls);
        self::assertFalse($plain->tls);
    }

    public function testTlsOptionsAccepted(): void
    {
        $client = new HttpClient(
            host: 'api.anthropic.com',
            port: 443,
            tls: true,
            tlsOptions: new TlsOptions(verifyPeer: true, hostName: 'api.anthropic.com'),
        );

        self::assertTrue($client->tls);
    }

    public function testConnectFailureSurfacesAsHttpException(): void
    {
        // Loopback port unlikely to have a listener: forces connect failure.
        $client = new HttpClient('127.0.0.1', 1, tls: false);

        $caught = null;
        $this->runScoped(static function (ExecutionScope $scope) use ($client, &$caught): void {
            try {
                $client->send($scope, HttpRequest::get('/'));
            } catch (HttpException $e) {
                $caught = $e;
            }
        });

        self::assertNotNull($caught);
        self::assertStringContainsString('connect', $caught->getMessage());
    }
}
