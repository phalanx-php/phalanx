<?php

declare(strict_types=1);

namespace Phalanx\Athena\Tests\Acceptance;

use Phalanx\Athena\Activity;
use Phalanx\Athena\Activity\Activity as ActivityRunner;
use Phalanx\Athena\Tests\Fixtures\ScopeStub;
use Phalanx\Athena\Tests\Fixtures\SyncRuntimeFactory;
use Phalanx\Athena\Tests\Fixtures\TestAgent;
use Phalanx\Athena\Turn\DefaultBuilder;
use Phalanx\Athena\Turn\Loop;
use Phalanx\Athena\Turn\Outcome;
use Phalanx\Cancellation\CancellationToken;
use Phalanx\Panoply\Context;
use Phalanx\Panoply\Cue\Output\TokenDelta;
use Phalanx\Panoply\Invocation;
use Phalanx\Panoply\Provider as ProviderContract;
use Phalanx\Panoply\Runtime;
use Phalanx\Panoply\Stream;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ScopeCancellationTest extends TestCase
{
    #[Test]
    public function cancelledScopeProducesCancelledActivityStateWithLifecycleCues(): void
    {
        $token = CancellationToken::create();
        $scope = new ScopeStub($token);

        $token->cancel();

        $provider = new ThrottledProvider();
        $loop = new Loop(new DefaultBuilder(), $provider, new SyncRuntimeFactory());
        $activity = new ActivityRunner($loop);

        $result = $activity($scope, new TestAgent(), new Activity\Config('act_cancel', Context::new(), 5));

        self::assertSame(Activity\State::Cancelled, $result->state);
        self::assertSame(Outcome::Cancelled, $result->outcome);
        self::assertInstanceOf(\Phalanx\Cancellation\Cancelled::class, $result->error);
    }

    #[Test]
    public function disposeRegistrationsRunWhenScopeIsCancelled(): void
    {
        $token = CancellationToken::create();
        $scope = new ScopeStub($token);
        $cleaned = false;

        $scope->onDispose(static function () use (&$cleaned): void {
            $cleaned = true;
        });

        $token->cancel();
        $scope->dispose();

        self::assertTrue($cleaned, 'Dispose registration must run when scope is cancelled and disposed');
    }
}

final class ThrottledProvider implements ProviderContract
{
    public function perform(Invocation $invocation, Runtime $runtime): Stream
    {
        return new Stream(static function (): \Generator {
            yield new TokenDelta('cue_t1', 1, 'act_cancel', null, null, new \DateTimeImmutable(), 'step');
            yield new TokenDelta('cue_t2', 2, 'act_cancel', null, null, new \DateTimeImmutable(), 'step');
        });
    }

    public function capabilities(): \Phalanx\Panoply\Capabilities
    {
        return \Phalanx\Panoply\Capabilities::empty();
    }
}
