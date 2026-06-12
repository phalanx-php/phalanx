<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Scope;

use Phalanx\Engine\Wiring;
use Phalanx\Err\Err;
use Phalanx\Err\Fault;
use Phalanx\Err\FaultEscaped;
use Phalanx\Err\Retryable;
use Phalanx\Err\Severity;
use Phalanx\Invocation\Caps;
use Phalanx\Invocation\Ctx;
use Phalanx\Invocation\Executable;
use Phalanx\Mark\Mark;
use Phalanx\Scope\Backoff;
use Phalanx\Scope\Scope;
use Phalanx\Scope\SyncScope;
use Phalanx\Supervision\Operation;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SyncScopeRunTest extends TestCase
{
    #[Test]
    public function aSuccessfulRunReturnsTheValueAndSkipsCompensation(): void
    {
        $probe = new KernelProbe(['ok']);

        $outcome = $this->scope($probe)->run(new ProbeTask());

        self::assertSame('done', $outcome);
        self::assertSame(1, $probe->frames);
        self::assertSame([], $probe->compensated);
        self::assertSame([1], $probe->attempts);
    }

    #[Test]
    public function aCtxOnlyTaskRunsWithoutCapsWiring(): void
    {
        $outcome = SyncScope::root(new Wiring())->run(new BareTask());

        self::assertSame('bare:1', $outcome);
    }

    #[Test]
    public function anExpectedErrReturnsWithoutRetry(): void
    {
        $probe = new KernelProbe(['expected', 'ok']);
        $scope = $this->scope($probe)->withRetry(3, Backoff::none());

        $outcome = $scope->run(new ProbeTask());

        self::assertInstanceOf(ExpectedErr::class, $outcome);
        self::assertSame(1, $probe->frames);
    }

    #[Test]
    public function aTransientErrRetriesWithAFreshFramePerAttempt(): void
    {
        $probe = new KernelProbe(['transient', 'transient', 'ok']);
        $scope = $this->scope($probe)->withRetry(3, Backoff::none());

        $outcome = $scope->run(new ProbeTask());

        self::assertSame('done', $outcome);
        self::assertSame(3, $probe->frames, 'every attempt rebuilds Caps (PV2-C22)');
        self::assertSame([1, 2, 3], $probe->attempts);
        self::assertCount(3, array_unique($probe->invocationIds), 'each attempt is a fresh frame');
    }

    #[Test]
    public function aRetryableFalseQualityOverridesTransientSeverity(): void
    {
        $probe = new KernelProbe(['stubborn', 'ok']);
        $scope = $this->scope($probe)->withRetry(3, Backoff::none());

        $outcome = $scope->run(new ProbeTask());

        self::assertInstanceOf(StubbornErr::class, $outcome);
        self::assertSame(1, $probe->frames);
    }

    #[Test]
    public function aRetryableTrueQualityOverridesNonTransientSeverity(): void
    {
        $probe = new KernelProbe(['willing', 'ok']);
        $scope = $this->scope($probe)->withRetry(2, Backoff::none());

        $outcome = $scope->run(new ProbeTask());

        self::assertSame('done', $outcome);
        self::assertSame(2, $probe->frames);
    }

    #[Test]
    public function budgetExhaustionReturnsTheLastErr(): void
    {
        $probe = new KernelProbe(['transient', 'transient', 'transient']);
        $scope = $this->scope($probe)->withRetry(2, Backoff::none());

        $outcome = $scope->run(new ProbeTask());

        self::assertInstanceOf(TransientErr::class, $outcome);
        self::assertSame(2, $probe->frames);
    }

    #[Test]
    public function withoutRetryForcesASingleAttempt(): void
    {
        $probe = new KernelProbe(['transient', 'ok']);
        $scope = $this->scope($probe)->withRetry(5, Backoff::none())->withoutRetry();

        $outcome = $scope->run(new ProbeTask());

        self::assertInstanceOf(TransientErr::class, $outcome);
        self::assertSame(1, $probe->frames);
    }

    #[Test]
    public function compensationFiresLifoOnErrAndPerAttempt(): void
    {
        $probe = new KernelProbe(['transient', 'ok']);
        $scope = $this->scope($probe)->withRetry(2, Backoff::none());

        $outcome = $scope->run(new CompensatingTask());

        self::assertSame('done', $outcome);
        self::assertSame(['attempt-1:second', 'attempt-1:first'], $probe->compensated);
    }

    #[Test]
    public function aThrowableBecomesAFaultEscapedAtTheRootAfterCompensation(): void
    {
        $probe = new KernelProbe(['throw']);

        try {
            $this->scope($probe)->run(new CompensatingTask());

            self::fail('Expected FaultEscaped.');
        } catch (FaultEscaped $escaped) {
            self::assertTrue($escaped->fault->isA(RuntimeException::class));
            self::assertSame('kernel.probe-compensating', $escaped->fault->operation);
            self::assertSame(['attempt-1:second', 'attempt-1:first'], $probe->compensated);
        }
    }

    #[Test]
    public function faultsAsAbsorbsAtTheDispatchSiteAndDeclinesKeepUnwinding(): void
    {
        $probe = new KernelProbe(['throw']);

        $absorbed = $this->scope($probe)
            ->faultsAs(static fn (Fault $fault): Err|Fault => $fault->isA(RuntimeException::class) ? new ExpectedErr() : $fault)
            ->run(new ProbeTask());

        self::assertInstanceOf(ExpectedErr::class, $absorbed);

        $declining = new KernelProbe(['throw']);

        $this->expectException(FaultEscaped::class);

        $this->scope($declining)
            ->faultsAs(static fn (Fault $fault): Err|Fault => $fault->isA(\TypeError::class) ? new ExpectedErr() : $fault)
            ->run(new ProbeTask());
    }

    #[Test]
    public function anExhaustedDeadlineBlocksRetriesAndFlipsTheCancelledProjection(): void
    {
        $probe = new KernelProbe(['transient', 'ok']);
        $scope = $this->scope($probe)->withRetry(5, Backoff::none())->withDeadline(Mark::zero());

        $outcome = $scope->run(new ProbeTask());

        self::assertInstanceOf(TransientErr::class, $outcome, 'no retry past the deadline');
        self::assertSame(1, $probe->frames);
        self::assertSame([true], $probe->cancelledSeen, 'deadline exhaustion projects as cancelled');
    }

    #[Test]
    public function cancellationPropagatesIntoTheFrameProjection(): void
    {
        $probe = new KernelProbe(['ok']);
        $scope = $this->scope($probe);

        $scope->cancel();
        $scope->run(new ProbeTask());

        self::assertSame([true], $probe->cancelledSeen);
    }

    private function scope(KernelProbe $probe): Scope
    {
        $wiring = new Wiring();
        $wiring->provide(ProbeCaps::class, static fn (Scope $frame): ProbeCaps => new ProbeCaps($probe, $frame));

        $probe->frames = 0;

        return SyncScope::root($wiring);
    }
}

final class KernelProbe
{
    public int $frames = 0;

    /** @var list<int> */
    public array $attempts = [];

    /** @var list<string> */
    public array $invocationIds = [];

    /** @var list<bool> */
    public array $cancelledSeen = [];

    /** @var list<string> */
    public array $compensated = [];

    /** @param list<string> $script */
    public function __construct(
        private array $script,
    ) {
    }

    public function observe(Ctx $ctx): string|Err
    {
        $this->attempts[] = $ctx->attempt->number;
        $this->invocationIds[] = $ctx->id->value;
        $this->cancelledSeen[] = $ctx->cancelled;

        $step = array_shift($this->script) ?? 'ok';

        return match ($step) {
            'ok' => 'done',
            'expected' => new ExpectedErr(),
            'transient' => new TransientErr(),
            'stubborn' => new StubbornErr(),
            'willing' => new WillingErr(),
            'throw' => throw new RuntimeException('probe exploded'),
            default => 'done',
        };
    }
}

final class ProbeCaps implements Caps
{
    public function __construct(
        private(set) KernelProbe $probe,
        private(set) Scope $scope,
    ) {
    }
}

/** @implements Executable<string|Err> */
#[Operation('kernel.probe')]
final class ProbeTask implements Executable
{
    public function __invoke(Ctx $ctx, ProbeCaps $caps): string|Err
    {
        $caps->probe->frames++;

        return $caps->probe->observe($ctx);
    }
}

/** @implements Executable<string|Err> */
#[Operation('kernel.probe-compensating')]
final class CompensatingTask implements Executable
{
    public function __invoke(Ctx $ctx, ProbeCaps $caps): string|Err
    {
        $caps->probe->frames++;

        $attempt = $ctx->attempt->number;
        $caps->scope->onErr(static function () use ($caps, $attempt): void {
            $caps->probe->compensated[] = "attempt-{$attempt}:first";
        });
        $caps->scope->onErr(static function () use ($caps, $attempt): void {
            $caps->probe->compensated[] = "attempt-{$attempt}:second";
        });

        return $caps->probe->observe($ctx);
    }
}

/** @implements Executable<string> */
final class BareTask implements Executable
{
    public function __invoke(Ctx $ctx): string
    {
        return 'bare:' . $ctx->attempt->number;
    }
}

final class ExpectedErr implements Err
{
    public Severity $severity {
        get => Severity::Expected;
    }
}

final class TransientErr implements Err
{
    public Severity $severity {
        get => Severity::Transient;
    }
}

final class StubbornErr implements Err, Retryable
{
    public Severity $severity {
        get => Severity::Transient;
    }

    public bool $retryable {
        get => false;
    }
}

final class WillingErr implements Err, Retryable
{
    public Severity $severity {
        get => Severity::Expected;
    }

    public bool $retryable {
        get => true;
    }
}
