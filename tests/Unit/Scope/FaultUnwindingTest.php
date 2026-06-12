<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Scope;

use DomainException;
use Phalanx\Engine\Wiring;
use Phalanx\Err\Err;
use Phalanx\Err\Fault;
use Phalanx\Err\FaultEscaped;
use Phalanx\Err\Severity;
use Phalanx\Invocation\Caps;
use Phalanx\Invocation\Ctx;
use Phalanx\Invocation\Executable;
use Phalanx\Scope\Scope;
use Phalanx\Scope\SyncScope;
use Phalanx\Supervision\Operation;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Throwable;
use TypeError;

final class FaultUnwindingTest extends TestCase
{
    #[Test]
    public function aChildFaultUnwindsThroughEveryFrameFiringCompensationInnerFirst(): void
    {
        $log = new UnwindLog();

        try {
            $this->scope($log)->run(new PlainParent());

            self::fail('Expected FaultEscaped.');
        } catch (FaultEscaped $escaped) {
            self::assertSame(['child-comp', 'parent-comp'], $log->events);
            self::assertTrue($escaped->fault->isA(DomainException::class));
            self::assertSame('kernel.child', $escaped->fault->operation, 'the ORIGIN operation survives unwinding');
        }
    }

    #[Test]
    public function theNearestAbsorberWinsAndTheParentReceivesAValue(): void
    {
        $log = new UnwindLog();

        $outcome = $this->scope($log)->run(new AbsorbingParent());

        self::assertSame('recovered', $outcome);
        self::assertSame(['child-comp'], $log->events, 'parent compensation must not fire on a successful parent');
    }

    #[Test]
    public function anOuterAbsorberCatchesWhatInnerFramesDeclined(): void
    {
        $log = new UnwindLog();

        $outcome = $this->scope($log)
            ->faultsAs(static fn (Fault $fault): Err|Fault => $fault->is(DomainException::class) ? new ChildFailed() : $fault)
            ->run(new PlainParent());

        self::assertInstanceOf(ChildFailed::class, $outcome);
        self::assertSame(['child-comp', 'parent-comp'], $log->events, 'both frames compensated before outer absorption');
    }

    #[Test]
    public function noRawThrowableEverCrossesTheRootBoundary(): void
    {
        try {
            $this->scope(new UnwindLog())->run(new PlainParent());

            self::fail('Expected an escape.');
        } catch (Throwable $caught) {
            self::assertInstanceOf(FaultEscaped::class, $caught, 'only FaultEscaped may cross the root');
        }
    }

    #[Test]
    public function aDecliningAbsorberThatMatchesNothingStillEscapes(): void
    {
        $this->expectException(FaultEscaped::class);

        $this->scope(new UnwindLog())
            ->faultsAs(static fn (Fault $fault): Err|Fault => $fault->isA(TypeError::class) ? new ChildFailed() : $fault)
            ->run(new PlainParent());
    }

    private function scope(UnwindLog $log): Scope
    {
        $wiring = new Wiring();
        $wiring->provide(ParentCaps::class, static fn (Scope $frame): ParentCaps => new ParentCaps($frame, $log));
        $wiring->provide(ChildCaps::class, static fn (Scope $frame): ChildCaps => new ChildCaps($frame, $log));

        return SyncScope::root($wiring);
    }
}

final class UnwindLog
{
    /** @var list<string> */
    public array $events = [];
}

final class ParentCaps implements Caps
{
    public function __construct(
        private(set) Scope $scope,
        private(set) UnwindLog $log,
    ) {
    }
}

final class ChildCaps implements Caps
{
    public function __construct(
        private(set) Scope $scope,
        private(set) UnwindLog $log,
    ) {
    }
}

/**
 * The declared union carries the Err an absorbing dispatch may inject into
 * the value channel (EM1.6: propagated types appear in the union).
 *
 * @implements Executable<string|ChildFailed>
 */
#[Operation('kernel.child')]
final class ThrowingChild implements Executable
{
    public function __invoke(Ctx $ctx, ChildCaps $caps): string|ChildFailed
    {
        $caps->scope->onErr(static function () use ($caps): void {
            $caps->log->events[] = 'child-comp';
        });

        throw new DomainException('child exploded');
    }
}

/** @implements Executable<string|ChildFailed> */
#[Operation('kernel.parent')]
final class PlainParent implements Executable
{
    public function __invoke(Ctx $ctx, ParentCaps $caps): string|ChildFailed
    {
        $caps->scope->onErr(static function () use ($caps): void {
            $caps->log->events[] = 'parent-comp';
        });

        return $caps->scope->run(new ThrowingChild());
    }
}

/** @implements Executable<string|ChildFailed> */
#[Operation('kernel.absorbing-parent')]
final class AbsorbingParent implements Executable
{
    public function __invoke(Ctx $ctx, ParentCaps $caps): string|ChildFailed
    {
        $caps->scope->onErr(static function () use ($caps): void {
            $caps->log->events[] = 'parent-comp';
        });

        $outcome = $caps->scope
            ->faultsAs(static fn (Fault $fault): Err|Fault => $fault->isA(DomainException::class) ? new ChildFailed() : $fault)
            ->run(new ThrowingChild());

        return $outcome instanceof ChildFailed ? 'recovered' : $outcome;
    }
}

final class ChildFailed implements Err
{
    public Severity $severity {
        get => Severity::Expected;
    }
}
