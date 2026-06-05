<?php

declare(strict_types=1);

namespace Phalanx\Runtime\Tests\Unit\Boot;

use Phalanx\Boot\AppContext;
use Phalanx\Boot\BootEvaluation;
use Phalanx\Boot\Probe;
use Phalanx\Boot\ProbeOutcome;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Testing\PhalanxTestCase;
use PHPUnit\Framework\Attributes\Test;

final class ProbeTest extends PhalanxTestCase
{
    // ── TCP probe ────────────────────────────────────────────────────────────

    #[Test]
    public function tcpProbePassesWhenListenerIsPresent(): void
    {
        $this->scope->run(
            static function (): void {
                [$server, $port] = self::openTcpListener();

                try {
                    $ev = Probe::tcp('127.0.0.1', $port)->evaluate(new AppContext());

                    self::assertTrue($ev->isPass(), sprintf('Expected pass but got %s: %s', $ev->status, $ev->message));
                } finally {
                    $server->close();
                }
            },
            'test.boot.probe.tcp-present',
        );
    }

    #[Test]
    public function tcpProbeFailsWhenPortIsUnused(): void
    {
        $port = self::closedLocalTcpPort();

        $this->scope->run(
            static function () use ($port): void {
                $ev = Probe::tcp(
                    '127.0.0.1',
                    $port,
                    0.1,
                    ProbeOutcome::FailBoot,
                )->evaluate(new AppContext());

                self::assertTrue($ev->isFail(), sprintf('Expected fail but got %s: %s', $ev->status, $ev->message));
                self::assertNotNull($ev->remediation);
            },
            'test.boot.probe.tcp-unused-fail',
        );
    }

    #[Test]
    public function tcpProbeWarnsWhenPortUnusedAndFailureModeIsFeatureUnavailable(): void
    {
        $port = self::closedLocalTcpPort();

        $this->scope->run(
            static function () use ($port): void {
                $ev = Probe::tcp(
                    '127.0.0.1',
                    $port,
                    0.1,
                    ProbeOutcome::FeatureUnavailable,
                )->evaluate(new AppContext());

                self::assertTrue($ev->isWarn(), sprintf('Expected warn but got %s: %s', $ev->status, $ev->message));
            },
            'test.boot.probe.tcp-unused-warn',
        );
    }

    #[Test]
    public function tcpKindIsCorrect(): void
    {
        $probe = Probe::tcp('127.0.0.1', 8000);

        self::assertSame(Probe::KIND_TCP, $probe->kind);
    }

    #[Test]
    public function tcpFailureModeIsStoredOnProbe(): void
    {
        $probe = Probe::tcp('127.0.0.1', 8000, 1.0, ProbeOutcome::FeatureUnavailable);

        self::assertSame(ProbeOutcome::FeatureUnavailable, $probe->failureMode);
    }

    // ── HTTP probe ───────────────────────────────────────────────────────────

    #[Test]
    public function httpProbeWarnsWhenStatusIsUnexpectedAndFailureModeIsFeatureUnavailable(): void
    {
        $this->scope->run(
            static function (ExecutionScope $scope): void {
                $ev = self::evaluateHttpProbeAgainstUnexpectedStatus($scope, ProbeOutcome::FeatureUnavailable);

                self::assertTrue($ev->isWarn(), sprintf('Expected warn but got %s: %s', $ev->status, $ev->message));
            },
            'test.boot.probe.http-unexpected-status-warn',
        );
    }

    #[Test]
    public function httpProbeFailsWhenStatusIsUnexpectedAndFailureModeIsFailBoot(): void
    {
        $this->scope->run(
            static function (ExecutionScope $scope): void {
                $ev = self::evaluateHttpProbeAgainstUnexpectedStatus($scope, ProbeOutcome::FailBoot);

                self::assertTrue($ev->isFail(), sprintf('Expected fail but got %s: %s', $ev->status, $ev->message));
            },
            'test.boot.probe.http-unexpected-status-fail',
        );
    }

    #[Test]
    public function httpProbeWarnsWhenUrlIsUnreachableAndFailureModeIsFeatureUnavailable(): void
    {
        $port = self::closedLocalTcpPort();

        $this->scope->run(
            static function () use ($port): void {
                $ev = Probe::http(
                    "http://127.0.0.1:{$port}/",
                    [200],
                    0.1,
                    ProbeOutcome::FeatureUnavailable,
                )->evaluate(new AppContext());

                self::assertTrue($ev->isWarn(), sprintf('Expected warn but got %s: %s', $ev->status, $ev->message));
                self::assertNotNull($ev->remediation);
            },
            'test.boot.probe.http-unreachable-warn',
        );
    }

    #[Test]
    public function httpProbeFailsWhenUrlIsUnreachableAndFailureModeIsFailBoot(): void
    {
        $port = self::closedLocalTcpPort();

        $this->scope->run(
            static function () use ($port): void {
                $ev = Probe::http(
                    "http://127.0.0.1:{$port}/",
                    [200],
                    0.1,
                    ProbeOutcome::FailBoot,
                )->evaluate(new AppContext());

                self::assertTrue($ev->isFail(), sprintf('Expected fail but got %s: %s', $ev->status, $ev->message));
                self::assertNotNull($ev->remediation);
            },
            'test.boot.probe.http-unreachable-fail',
        );
    }

    #[Test]
    public function httpKindIsCorrect(): void
    {
        $probe = Probe::http('http://192.0.2.1/');

        self::assertSame(Probe::KIND_HTTP, $probe->kind);
    }

    // ── Callable probe ───────────────────────────────────────────────────────

    #[Test]
    public function callableProbePassesWhenFnReturnsTrue(): void
    {
        $ctx = new AppContext();
        $ev = Probe::callable(
            static fn (AppContext $_c): bool => true,
            'healthy check',
        )->evaluate($ctx);

        self::assertTrue($ev->isPass());
    }

    #[Test]
    public function callableProbeFailsWhenFnReturnsFalseWithFailBoot(): void
    {
        $ctx = new AppContext();
        $ev = Probe::callable(
            static fn (AppContext $_c): bool => false,
            'failing check',
            ProbeOutcome::FailBoot,
        )->evaluate($ctx);

        self::assertTrue($ev->isFail());
    }

    #[Test]
    public function callableProbeWarnsWhenFnReturnsFalseWithFeatureUnavailable(): void
    {
        $ctx = new AppContext();
        $ev = Probe::callable(
            static fn (AppContext $_c): bool => false,
            'optional feature check',
            ProbeOutcome::FeatureUnavailable,
        )->evaluate($ctx);

        self::assertTrue($ev->isWarn());
    }

    #[Test]
    public function callableProbeFailsWithStringReasonAsRemediation(): void
    {
        $ctx = new AppContext();
        $ev = Probe::callable(
            static fn (AppContext $_c): string => 'install the redis extension',
            'redis ext',
            ProbeOutcome::FailBoot,
        )->evaluate($ctx);

        self::assertTrue($ev->isFail());
        self::assertSame('install the redis extension', $ev->remediation);
    }

    #[Test]
    public function callableProbePassThroughsBootEvaluationUnchanged(): void
    {
        $ctx = new AppContext();
        $ev = Probe::callable(
            static fn (AppContext $_c): BootEvaluation => BootEvaluation::warn('custom warn from probe'),
            'custom',
        )->evaluate($ctx);

        self::assertTrue($ev->isWarn());
        self::assertSame('custom warn from probe', $ev->message);
    }

    #[Test]
    public function callableKindIsCorrect(): void
    {
        $probe = Probe::callable(static fn (AppContext $_c): bool => true, 'desc');

        self::assertSame(Probe::KIND_CALLABLE, $probe->kind);
    }

    /** @return array{0: \Swoole\Coroutine\Socket, 1: int} */
    private static function openTcpListener(): array
    {
        $server = new \Swoole\Coroutine\Socket(AF_INET, SOCK_STREAM, SOL_TCP);
        self::assertTrue($server->bind('127.0.0.1', 0), 'Could not bind TCP listener.');
        self::assertTrue($server->listen(), 'Could not listen on TCP listener.');

        $name = $server->getsockname();
        self::assertIsArray($name, 'Could not determine listener address.');
        self::assertArrayHasKey('port', $name, 'TCP listener address did not include a port.');

        return [$server, (int) $name['port']];
    }

    private static function closedLocalTcpPort(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($server, 'Could not bind temporary TCP listener.');

        $name = stream_socket_get_name($server, false);
        self::assertIsString($name, 'Could not determine temporary listener address.');
        self::assertSame(1, preg_match('/:(\d+)$/', $name, $matches), 'Temporary listener address had no port.');

        fclose($server);

        return (int) $matches[1];
    }

    private static function evaluateHttpProbeAgainstUnexpectedStatus(
        ExecutionScope $scope,
        ProbeOutcome $failureMode,
    ): BootEvaluation {
        [$server, $port] = self::openTcpListener();

        $scope->go(
            static function () use ($server): void {
                $client = $server->accept(1.0);
                if (!$client instanceof \Swoole\Coroutine\Socket) {
                    return;
                }

                $client->recv(1024);
                $client->send("HTTP/1.1 503 Service Unavailable\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
                $client->close();
            },
            'test.boot.probe.http-server',
        );

        try {
            return Probe::http(
                "http://127.0.0.1:{$port}/",
                [200],
                1.0,
                $failureMode,
            )->evaluate(new AppContext());
        } finally {
            $server->close();
        }
    }

}
