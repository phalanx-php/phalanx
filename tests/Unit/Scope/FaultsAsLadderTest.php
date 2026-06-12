<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Scope;

use DomainException;
use Exception;
use InvalidArgumentException;
use Phalanx\Engine\Wiring;
use Phalanx\Err\Err;
use Phalanx\Err\Fault;
use Phalanx\Err\FaultBorn;
use Phalanx\Err\FaultEscaped;
use Phalanx\Err\Severity;
use Phalanx\Scope\Scope;
use Phalanx\Scope\SyncScope;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TypeError;

final class FaultsAsLadderTest extends TestCase
{
    #[Test]
    public function aBareMapArmConstructsItsFaultBornErrFromTheLiveFault(): void
    {
        $probe = new KernelProbe(['throw']);

        $outcome = $this->scope($probe)
            ->faultsAs([RuntimeException::class => MappedFailure::class])
            ->run(new ProbeTask());

        self::assertInstanceOf(MappedFailure::class, $outcome);
        self::assertTrue($outcome->fault->isA(RuntimeException::class));
        self::assertSame('kernel.probe', $outcome->fault->operation);
    }

    #[Test]
    public function anUnmatchedBareMapDeclinesAndTheFaultKeepsUnwinding(): void
    {
        $probe = new KernelProbe(['throw']);

        $this->expectException(FaultEscaped::class);

        $this->scope($probe)
            ->faultsAs([TypeError::class => MappedFailure::class])
            ->run(new ProbeTask());
    }

    #[Test]
    public function theFirstMatchingArmWinsInMapIterationOrder(): void
    {
        $probe = new KernelProbe(['throw']);

        $outcome = $this->scope($probe)
            ->faultsAs([
                Exception::class => MappedFailure::class,
                RuntimeException::class => OverridingFailure::class,
            ])
            ->run(new ProbeTask());

        self::assertInstanceOf(MappedFailure::class, $outcome, 'chain-wide lineage matched the earlier arm first');
    }

    #[Test]
    public function aFaultBuiltMapMixesErrInstancesAndFaultBornArms(): void
    {
        $probe = new KernelProbe(['throw']);
        $prebuilt = new ExpectedErr();

        $outcome = $this->scope($probe)
            ->faultsAs(static fn (Fault $f): array => [
                DomainException::class => MappedFailure::class,
                RuntimeException::class => $prebuilt,
            ])
            ->run(new ProbeTask());

        self::assertSame($prebuilt, $outcome, 'the instance arm rides back untouched');

        $constructing = new KernelProbe(['throw']);

        $built = $this->scope($constructing)
            ->faultsAs(static fn (Fault $f): array => [RuntimeException::class => MappedFailure::class])
            ->run(new ProbeTask());

        self::assertInstanceOf(MappedFailure::class, $built);
    }

    #[Test]
    public function theFaultBuiltMapClosureNeverRunsOnASuccessfulOutcome(): void
    {
        $probe = new KernelProbe(['ok']);
        $ran = false;

        $outcome = $this->scope($probe)
            ->faultsAs(static function (Fault $f) use (&$ran): array {
                $ran = true;

                return [RuntimeException::class => MappedFailure::class];
            })
            ->run(new ProbeTask());

        self::assertSame('done', $outcome);
        self::assertFalse($ran, 'the map builds only on the fault path');
    }

    #[Test]
    public function anErrInstanceInABareMapIsRefusedAtRegistrationNamingTheArm(): void
    {
        $scope = SyncScope::root(new Wiring());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('RuntimeException');

        $scope->faultsAs([RuntimeException::class => new ExpectedErr()]);
    }

    #[Test]
    public function aNonFaultBornArmInABareMapIsRefusedAtRegistration(): void
    {
        $scope = SyncScope::root(new Wiring());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must map to a FaultBorn class-string');

        $scope->faultsAs([RuntimeException::class => \stdClass::class]);
    }

    #[Test]
    public function spreadMergedMapsConcatenateAndTheLaterDuplicateArmWins(): void
    {
        $probe = new KernelProbe(['throw']);
        $base = [RuntimeException::class => MappedFailure::class];
        $override = [RuntimeException::class => OverridingFailure::class];

        $outcome = $this->scope($probe)
            ->faultsAs([...$base, ...$override])
            ->run(new ProbeTask());

        self::assertInstanceOf(OverridingFailure::class, $outcome);
    }

    #[Test]
    public function aFaultBuiltMapThatMatchesNothingDeclinesToTheNextAbsorber(): void
    {
        $probe = new KernelProbe(['throw']);

        $outcome = $this->scope($probe)
            ->faultsAs(static fn (Fault $f): Err|Fault => $f->isA(RuntimeException::class) ? new ExpectedErr() : $f)
            ->faultsAs(static fn (Fault $f): array => [DomainException::class => MappedFailure::class])
            ->run(new ProbeTask());

        self::assertInstanceOf(ExpectedErr::class, $outcome, 'the declining map handed the fault outward');
    }

    private function scope(KernelProbe $probe): Scope
    {
        $wiring = new Wiring();
        $wiring->provide(ProbeCaps::class, static fn (Scope $frame): ProbeCaps => new ProbeCaps($probe, $frame));

        return SyncScope::root($wiring);
    }
}

final class MappedFailure implements FaultBorn
{
    public Severity $severity {
        get => Severity::Expected;
    }

    public function __construct(
        private(set) Fault $fault,
    ) {
    }

    public static function fromFault(Fault $f): static
    {
        return new self($f);
    }
}

final class OverridingFailure implements FaultBorn
{
    public Severity $severity {
        get => Severity::Expected;
    }

    public function __construct(
        private(set) Fault $fault,
    ) {
    }

    public static function fromFault(Fault $f): static
    {
        return new self($f);
    }
}
