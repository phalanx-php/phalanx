<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Boot;

use Phalanx\Boot\AppContext;
use Phalanx\Boot\BootEvaluation;
use Phalanx\Boot\Probe;
use Phalanx\Boot\ProbeOutcome;
use Phalanx\Testing\PhalanxTestCase;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\Attributes\Test;

final class ProbeTest extends PhalanxTestCase
{
    /** @var resource|null */
    private mixed $tcpServer = null;
    private int $tcpPort = 0;

    #[Before]
    protected function bindTcpListener(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertNotFalse($server, sprintf('Could not bind TCP listener: %s (%d)', $errstr, $errno));
        $this->tcpServer = $server;

        $name = stream_socket_get_name($server, false);
        self::assertNotFalse($name, 'Could not determine listener address');
        $this->tcpPort = (int) substr($name, strrpos($name, ':') + 1);
    }

    #[After]
    protected function closeTcpListener(): void
    {
        if ($this->tcpServer !== null) {
            fclose($this->tcpServer);
            $this->tcpServer = null;
        }
    }

    // ── TCP probe ────────────────────────────────────────────────────────────

    #[Test]
    public function tcpProbePassesWhenListenerIsPresent(): void
    {
        $ev = Probe::tcp('127.0.0.1', $this->tcpPort)->evaluate(AppContext::test());

        self::assertTrue($ev->isPass(), sprintf('Expected pass but got %s: %s', $ev->status, $ev->message));
    }

    #[Test]
    public function tcpProbeFailsWhenPortIsUnused(): void
    {
        // Pick a port known to be closed: close our listener first to free it.
        $port = $this->tcpPort;
        fclose($this->tcpServer);
        $this->tcpServer = null;

        $ev = Probe::tcp('127.0.0.1', $port, 0.1, ProbeOutcome::FailBoot)->evaluate(AppContext::test());

        self::assertTrue($ev->isFail(), sprintf('Expected fail but got %s: %s', $ev->status, $ev->message));
        self::assertNotNull($ev->remediation);
    }

    #[Test]
    public function tcpProbeWarnsWhenPortUnusedAndFailureModeIsFeatureUnavailable(): void
    {
        $port = $this->tcpPort;
        fclose($this->tcpServer);
        $this->tcpServer = null;

        $ev = Probe::tcp('127.0.0.1', $port, 0.1, ProbeOutcome::FeatureUnavailable)->evaluate(AppContext::test());

        self::assertTrue($ev->isWarn(), sprintf('Expected warn but got %s: %s', $ev->status, $ev->message));
    }

    #[Test]
    public function tcpKindIsCorrect(): void
    {
        $probe = Probe::tcp('127.0.0.1', $this->tcpPort);

        self::assertSame(Probe::KIND_TCP, $probe->kind);
    }

    #[Test]
    public function tcpFailureModeIsStoredOnProbe(): void
    {
        $probe = Probe::tcp('127.0.0.1', $this->tcpPort, 1.0, ProbeOutcome::FeatureUnavailable);

        self::assertSame(ProbeOutcome::FeatureUnavailable, $probe->failureMode);
    }

    // ── HTTP probe ───────────────────────────────────────────────────────────

    #[Test]
    public function httpProbeWarnsWhenUrlUnreachableAndFailureModeIsFeatureUnavailable(): void
    {
        // Use an address in the TEST-NET reserved range — should always be unreachable.
        $ev = Probe::http(
            'http://192.0.2.1/',
            [200],
            0.1,
            ProbeOutcome::FeatureUnavailable,
        )->evaluate(AppContext::test());

        self::assertTrue($ev->isWarn(), sprintf('Expected warn but got %s: %s', $ev->status, $ev->message));
    }

    #[Test]
    public function httpProbeFailsWhenUrlUnreachableAndFailureModeIsFailBoot(): void
    {
        $ev = Probe::http(
            'http://192.0.2.1/',
            [200],
            0.1,
            ProbeOutcome::FailBoot,
        )->evaluate(AppContext::test());

        self::assertTrue($ev->isFail(), sprintf('Expected fail but got %s: %s', $ev->status, $ev->message));
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
        $ctx = AppContext::test();
        $ev = Probe::callable(
            static fn (AppContext $c): bool => true,
            'healthy check',
        )->evaluate($ctx);

        self::assertTrue($ev->isPass());
    }

    #[Test]
    public function callableProbeFailsWhenFnReturnsFalseWithFailBoot(): void
    {
        $ctx = AppContext::test();
        $ev = Probe::callable(
            static fn (AppContext $c): bool => false,
            'failing check',
            ProbeOutcome::FailBoot,
        )->evaluate($ctx);

        self::assertTrue($ev->isFail());
    }

    #[Test]
    public function callableProbeWarnsWhenFnReturnsFalseWithFeatureUnavailable(): void
    {
        $ctx = AppContext::test();
        $ev = Probe::callable(
            static fn (AppContext $c): bool => false,
            'optional feature check',
            ProbeOutcome::FeatureUnavailable,
        )->evaluate($ctx);

        self::assertTrue($ev->isWarn());
    }

    #[Test]
    public function callableProbeFailsWithStringReasonAsRemediation(): void
    {
        $ctx = AppContext::test();
        $ev = Probe::callable(
            static fn (AppContext $c): string => 'install the redis extension',
            'redis ext',
            ProbeOutcome::FailBoot,
        )->evaluate($ctx);

        self::assertTrue($ev->isFail());
        self::assertSame('install the redis extension', $ev->remediation);
    }

    #[Test]
    public function callableProbePassThroughsBootEvaluationUnchanged(): void
    {
        $ctx = AppContext::test();
        $ev = Probe::callable(
            static fn (AppContext $c): BootEvaluation => BootEvaluation::warn('custom warn from probe'),
            'custom',
        )->evaluate($ctx);

        self::assertTrue($ev->isWarn());
        self::assertSame('custom warn from probe', $ev->message);
    }

    #[Test]
    public function callableKindIsCorrect(): void
    {
        $probe = Probe::callable(static fn (AppContext $c): bool => true, 'desc');

        self::assertSame(Probe::KIND_CALLABLE, $probe->kind);
    }
}
