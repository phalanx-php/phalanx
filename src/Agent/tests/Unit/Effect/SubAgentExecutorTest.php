<?php

declare(strict_types=1);

namespace Phalanx\Agent\Tests\Unit\Effect;

use Phalanx\Agent\Effect\Context;
use Phalanx\Agent\Effect\Resolution;
use Phalanx\Agent\Effect\SubAgentExecutor;
use Phalanx\Agent\Testing\ScopeStub;
use Phalanx\Cancellation\CancellationToken;
use Phalanx\Cancellation\Cancelled;
use Phalanx\AiProviders\Cue\Effect\Requested;
use Phalanx\AiProviders\Effect\Kind;
use Phalanx\Scope\TaskScope;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SubAgentExecutorTest extends TestCase
{
    #[Test]
    public function successfulSubAgentReturnsRoutedOutcome(): void
    {
        $executor = new SubAgentExecutor(static fn() => ['answer' => 42]);
        $outcome = $executor(new ScopeStub(), self::makeRequest(), self::makeContext());

        self::assertSame(Resolution::SubAgent, $outcome->resolution);
        self::assertSame(['answer' => 42], $outcome->data);
        self::assertNull($outcome->error);
        self::assertFalse($outcome->halt);
    }

    #[Test]
    public function subAgentFailureReturnsFailed(): void
    {
        $error = new \RuntimeException('zeus failed');
        $executor = new SubAgentExecutor(static function () use ($error): never {
            throw $error;
        });
        $outcome = $executor(new ScopeStub(), self::makeRequest(), self::makeContext());

        self::assertSame(Resolution::SubAgent, $outcome->resolution);
        self::assertSame($error, $outcome->error);
        self::assertNull($outcome->data);
        self::assertNotNull($outcome->effect);
        self::assertTrue($outcome->effect->isFailed());
    }

    #[Test]
    public function cancellationPropagates(): void
    {
        $this->expectException(Cancelled::class);

        $executor = new SubAgentExecutor(static function (): never {
            throw new Cancelled('parent cancelled');
        });
        $executor(new ScopeStub(), self::makeRequest(), self::makeContext());
    }

    #[Test]
    public function runnerReceivesCorrectArguments(): void
    {
        $captured = [];
        $runner = static function (TaskScope $s, Requested $r, Context $c) use (&$captured): string {
            $captured = ['scope' => $s, 'request' => $r, 'context' => $c];
            return 'received';
        };
        $executor = new SubAgentExecutor($runner);

        $scope = new ScopeStub();
        $request = self::makeRequest();
        $context = self::makeContext();

        $executor($scope, $request, $context);

        self::assertSame($scope, $captured['scope']);
        self::assertSame($request, $captured['request']);
        self::assertSame($context, $captured['context']);
    }

    #[Test]
    public function durationIsTracked(): void
    {
        $executor = new SubAgentExecutor(static fn() => 'done');
        $outcome = $executor(new ScopeStub(), self::makeRequest(), self::makeContext());

        self::assertNotNull($outcome->effect);
        self::assertTrue($outcome->effect->isSucceeded());
        self::assertGreaterThanOrEqual(0, $outcome->effect->durationMs);
    }

    #[Test]
    public function preCancelledScopeThrowsBeforeRunnerExecutes(): void
    {
        $ran = false;
        $executor = new SubAgentExecutor(static function () use (&$ran): string {
            $ran = true;
            return 'should not reach';
        });

        $token = CancellationToken::create();
        $token->cancel();
        $scope = new ScopeStub($token);

        $this->expectException(Cancelled::class);

        $executor($scope, self::makeRequest(), self::makeContext());
    }

    private static function makeRequest(): Requested
    {
        return new Requested(
            id: 'cue_test',
            sequence: 1,
            activityId: 'act_1',
            invocationId: 'inv_1',
            agentId: 'agent_1',
            at: new \DateTimeImmutable(),
            effectId: 'sub_agent_investigator',
            kind: Kind::Custom,
            summary: 'Run investigator sub-agent',
            arguments: ['task' => 'investigate'],
            requiresApproval: false,
        );
    }

    private static function makeContext(): Context
    {
        return new Context(
            activityId: 'act_1',
            invocationId: 'inv_1',
            agentId: 'agent_1',
        );
    }
}
