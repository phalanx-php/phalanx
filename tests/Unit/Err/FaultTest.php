<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Err;

use InvalidArgumentException;
use LogicException;
use Phalanx\Err\Fault;
use Phalanx\Err\FaultLink;
use Phalanx\Invocation\Attempt;
use Phalanx\Invocation\InvocationId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

final class FaultTest extends TestCase
{
    #[Test]
    public function lineageIsCapturedPerChainLinkAtConstruction(): void
    {
        $fault = $this->fault();

        self::assertCount(2, $fault->chain);
        self::assertSame(RuntimeException::class, $fault->chain[0]->class);
        self::assertSame('outer failed', $fault->chain[0]->message);
        self::assertContains(Throwable::class, $fault->chain[0]->lineage);
        self::assertSame(InvalidArgumentException::class, $fault->chain[1]->class);
    }

    #[Test]
    public function isaMatchesTheThrownThrowablesOwnLineageOnly(): void
    {
        $fault = $this->fault();

        self::assertTrue($fault->isA(RuntimeException::class));
        self::assertTrue($fault->isA(\Exception::class));
        self::assertTrue($fault->isA(LogicException::class, RuntimeException::class));
        self::assertFalse($fault->isA(InvalidArgumentException::class));
    }

    #[Test]
    public function isWalksLineageAcrossTheEntireCausalChain(): void
    {
        $fault = $this->fault();

        self::assertTrue($fault->is(InvalidArgumentException::class));
        self::assertTrue($fault->is(LogicException::class));
        self::assertFalse($fault->is(\TypeError::class));
    }

    #[Test]
    public function matchingWorksOnRehydratedChainsWithoutTheThrowableClasses(): void
    {
        $fault = new Fault(
            chain: [new FaultLink('Vendor\\Gone\\UpstreamTimeout', ['Vendor\\Gone\\UpstreamTimeout', 'Vendor\\Gone\\TransportException'], 'timed out')],
            invocationId: InvocationId::of('run-7'),
            attempt: Attempt::first(),
            operation: 'billing.charge',
        );

        self::assertTrue($fault->isA('Vendor\\Gone\\TransportException'));
        self::assertFalse($fault->isA(RuntimeException::class));
        self::assertSame('billing.charge', $fault->operation);
    }

    private function fault(): Fault
    {
        $inner = new InvalidArgumentException('inner cause');
        $outer = new RuntimeException('outer failed', previous: $inner);

        return Fault::fromThrowable($outer, InvocationId::of('run-1'), Attempt::of(2), 'billing.charge');
    }
}
