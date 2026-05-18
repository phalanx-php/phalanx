<?php

declare(strict_types=1);

namespace Phalanx\Athena\Tests\Unit\Effect;

use Phalanx\Athena\Effect\BuiltInExecutor;
use Phalanx\Athena\Effect\Context;
use Phalanx\Athena\Effect\Resolution;
use Phalanx\Athena\Tests\Fixtures\ScopeStub;
use Phalanx\Panoply\Cue\Effect\Requested;
use Phalanx\Panoply\Effect\Kind;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BuiltInExecutorTest extends TestCase
{
    #[Test]
    public function noopReturnsBuiltInResolutionWithNullData(): void
    {
        $executor = new BuiltInExecutor();
        $outcome  = $executor(new ScopeStub(), self::makeRequest('noop'), self::makeContext());

        self::assertSame(Resolution::BuiltIn, $outcome->resolution);
        self::assertNull($outcome->data);
        self::assertNull($outcome->error);
    }

    #[Test]
    public function echoReturnsRequestArguments(): void
    {
        $args     = ['message' => 'sparta', 'count' => 300];
        $executor = new BuiltInExecutor();
        $outcome  = $executor(new ScopeStub(), self::makeRequest('echo', $args), self::makeContext());

        self::assertSame(Resolution::BuiltIn, $outcome->resolution);
        self::assertSame($args, $outcome->data);
    }

    #[Test]
    public function haltReturnsHaltSentinel(): void
    {
        $executor = new BuiltInExecutor();
        $outcome  = $executor(new ScopeStub(), self::makeRequest('halt'), self::makeContext());

        self::assertSame(Resolution::BuiltIn, $outcome->resolution);
        self::assertSame('__halt__', $outcome->data);
    }

    #[Test]
    public function unknownEffectIdThrows(): void
    {
        $this->expectException(\ValueError::class);

        $executor = new BuiltInExecutor();
        $executor(new ScopeStub(), self::makeRequest('unknown'), self::makeContext());
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private static function makeRequest(string $effectId, array $arguments = []): Requested
    {
        return new Requested(
            id: 'cue-01',
            sequence: 1,
            activityId: 'act-01',
            invocationId: null,
            agentId: null,
            at: new \DateTimeImmutable(),
            effectId: $effectId,
            kind: Kind::Custom,
            summary: $effectId,
            arguments: $arguments,
            requiresApproval: false,
        );
    }

    private static function makeContext(): Context
    {
        return new Context(
            activityId: 'act-01',
            invocationId: null,
            agentId: null,
        );
    }
}
