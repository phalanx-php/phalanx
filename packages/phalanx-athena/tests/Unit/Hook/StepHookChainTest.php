<?php

declare(strict_types=1);

namespace Phalanx\Athena\Tests\Unit\Hook;

use Phalanx\Athena\Hook\StepContext;
use Phalanx\Athena\Hook\StepHook;
use Phalanx\Athena\Hook\StepHookChain;
use Phalanx\Athena\Hook\StepHookResult;
use Phalanx\Athena\Tests\Fixtures\ScopeStub;
use Phalanx\Athena\Turn\Config;
use Phalanx\Athena\Turn\Outcome;
use Phalanx\Panoply\Context;
use Phalanx\Panoply\Conversation\Log;
use Phalanx\Panoply\Effects;
use Phalanx\Panoply\Invocation;
use Phalanx\Panoply\Output;
use Phalanx\Panoply\Provider\Needs as ProviderNeeds;
use Phalanx\Panoply\Transport\Needs as TransportNeeds;
use Phalanx\Scope\TaskScope;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StepHookChainTest extends TestCase
{
    #[Test]
    public function emptyChainReturnsContinue(): void
    {
        $chain  = new StepHookChain();
        $scope  = new ScopeStub();
        $result = $chain->notify($scope, self::makeContext());

        self::assertSame(Outcome::Continue, $result->outcome);
        self::assertNull($result->error);
    }

    #[Test]
    public function runsHooksInOrder(): void
    {
        $order = [];

        $first = new class ($order) implements StepHook {
            public function __construct(private array &$order)
            {
            }

            public function __invoke(TaskScope $scope, StepContext $context): StepHookResult
            {
                $this->order[] = 'first';
                return StepHookResult::continue();
            }
        };

        $second = new class ($order) implements StepHook {
            public function __construct(private array &$order)
            {
            }

            public function __invoke(TaskScope $scope, StepContext $context): StepHookResult
            {
                $this->order[] = 'second';
                return StepHookResult::continue();
            }
        };

        $chain  = new StepHookChain([$first, $second]);
        $result = $chain->notify(new ScopeStub(), self::makeContext());

        self::assertSame(Outcome::Continue, $result->outcome);
        self::assertSame(['first', 'second'], $order);
    }

    #[Test]
    public function shortCircuitsOnTerminalResult(): void
    {
        $ran = [];

        $first = new class ($ran) implements StepHook {
            public function __construct(private array &$ran)
            {
            }

            public function __invoke(TaskScope $scope, StepContext $context): StepHookResult
            {
                $this->ran[] = 'first';
                return StepHookResult::stop(Outcome::Complete);
            }
        };

        $second = new class ($ran) implements StepHook {
            public function __construct(private array &$ran)
            {
            }

            public function __invoke(TaskScope $scope, StepContext $context): StepHookResult
            {
                $this->ran[] = 'second';
                return StepHookResult::continue();
            }
        };

        $chain  = new StepHookChain([$first, $second]);
        $result = $chain->notify(new ScopeStub(), self::makeContext());

        self::assertSame(Outcome::Complete, $result->outcome);
        self::assertSame(['first'], $ran);
    }

    #[Test]
    public function propagatesFailResult(): void
    {
        $error = new \RuntimeException('hook failed');

        $hook = new class ($error) implements StepHook {
            public function __construct(private \Throwable $error)
            {
            }

            public function __invoke(TaskScope $scope, StepContext $context): StepHookResult
            {
                return StepHookResult::fail($this->error);
            }
        };

        $chain  = new StepHookChain([$hook]);
        $result = $chain->notify(new ScopeStub(), self::makeContext());

        self::assertSame(Outcome::Failed, $result->outcome);
        self::assertSame($error, $result->error);
    }

    #[Test]
    public function hookExceptionPropagatesUnwrapped(): void
    {
        $hook = new class implements StepHook {
            public function __invoke(TaskScope $scope, StepContext $context): StepHookResult
            {
                throw new \RuntimeException('hook exploded');
            }
        };

        $chain = new StepHookChain([$hook]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('hook exploded');

        $chain->notify(new ScopeStub(), self::makeContext());
    }

    private static function makeContext(): StepContext
    {
        $config = new Config('act_test', Context::new());
        $log    = Log::from([]);

        $invocation = Invocation::of(
            id: 'inv_test',
            agentId: 'agent_test',
            activityId: 'act_test',
            contextHash: 'hash_test',
            instructions: 'Test instructions.',
            output: Output::text(),
            effects: Effects::none(),
            provider: ProviderNeeds::new(),
            transport: TransportNeeds::new(),
        );

        return StepContext::beforeInvocation($config, $log, $invocation);
    }
}
