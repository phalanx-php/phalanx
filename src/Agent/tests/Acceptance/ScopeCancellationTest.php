<?php

declare(strict_types=1);

namespace Phalanx\Agent\Tests\Acceptance;

use Phalanx\Agent\Activity;
use Phalanx\Agent\Activity\Activity as ActivityRunner;
use Phalanx\Agent\Testing\ScopeStub;
use Phalanx\Agent\Tests\Fixtures\SyncRuntimeFactory;
use Phalanx\Agent\Tests\Fixtures\TestAgent;
use Phalanx\Agent\Turn\DefaultBuilder;
use Phalanx\Agent\Turn\Loop;
use Phalanx\Agent\Turn\Outcome;
use Phalanx\Cancellation\CancellationToken;
use Phalanx\AiProviders\Context;
use Phalanx\AiProviders\Cue\Output\TokenDelta;
use Phalanx\AiProviders\Invocation;
use Phalanx\AiProviders\Provider as ProviderContract;
use Phalanx\AiProviders\Runtime;
use Phalanx\AiProviders\Stream;
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

    public function capabilities(): \Phalanx\AiProviders\Capabilities
    {
        return \Phalanx\AiProviders\Capabilities::empty();
    }
}
