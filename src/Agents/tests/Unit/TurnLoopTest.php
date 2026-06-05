<?php

declare(strict_types=1);

namespace Phalanx\Agents\Tests\Unit;

use Phalanx\Agents\Activity;
use Phalanx\Agents\Exception\MaxInvocationsReached;
use Phalanx\Agents\Hook\StepContext;
use Phalanx\Agents\Hook\StepHook;
use Phalanx\Agents\Hook\StepHookResult;
use Phalanx\Agents\Testing\ScopeStub;
use Phalanx\Agents\Tests\Fixtures\SyncRuntimeFactory;
use Phalanx\Agents\Tests\Fixtures\TestAgent;
use Phalanx\Agents\Turn\DefaultBuilder;
use Phalanx\Agents\Turn\Loop;
use Phalanx\Agents\Turn\Outcome;
use Phalanx\AiProviders\Capabilities;
use Phalanx\AiProviders\Context;
use Phalanx\AiProviders\Conversation\Record\Message;
use Phalanx\AiProviders\Conversation\Record\ToolCall;
use Phalanx\AiProviders\Conversation\Record\ToolResult;
use Phalanx\AiProviders\Cue\Effect\Requested;
use Phalanx\AiProviders\Cue\Output\Channel;
use Phalanx\AiProviders\Cue\Output\TokenDelta;
use Phalanx\AiProviders\Cue\Output\TokenStop;
use Phalanx\AiProviders\Cue\StopReason;
use Phalanx\AiProviders\Effect\Kind as EffectKind;
use Phalanx\AiProviders\Invocation;
use Phalanx\AiProviders\Provider as ProviderContract;
use Phalanx\AiProviders\Provider\Fake\Provider;
use Phalanx\AiProviders\Runtime;
use Phalanx\AiProviders\Stream;
use Phalanx\Scope\TaskScope;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TurnLoopTest extends TestCase
{
    #[Test]
    public function fakeProviderStreamCompletesActivityAndAppendsAssistantMessage(): void
    {
        $at = new \DateTimeImmutable('2026-05-17T12:00:00Z');
        $provider = new Provider([
            new TokenDelta('cue_1', 1, 'act_1', null, 'agent-test-agent', $at, 'Hello '),
            new TokenDelta('cue_2', 2, 'act_1', null, 'agent-test-agent', $at, 'world'),
            new TokenStop('cue_3', 3, 'act_1', null, 'agent-test-agent', $at, StopReason::EndOfTurn),
        ], Capabilities::empty());

        $loop = new Loop(new DefaultBuilder(), $provider, new SyncRuntimeFactory());

        $result = $loop(new ScopeStub(), new TestAgent(), new Activity\Config('act_1', Context::new(), 3));

        self::assertSame(Activity\State::Completed, $result->state);
        self::assertSame(Outcome::Complete, $result->outcome);
        self::assertSame(1, $result->invocations);

        $records = $result->log->toArray();
        self::assertCount(1, $records);
        self::assertInstanceOf(Message::class, $records[0]);
        self::assertSame('Hello world', $records[0]->text);
    }

    #[Test]
    public function thinkingAndReasoningDeltasDoNotBecomeAssistantMessageText(): void
    {
        $at = new \DateTimeImmutable('2026-05-17T12:00:00Z');
        $provider = new Provider([
            new TokenDelta('cue_1', 1, 'act_1', null, 'agent-test-agent', $at, 'thinking ', Channel::Thinking),
            new TokenDelta('cue_2', 2, 'act_1', null, 'agent-test-agent', $at, 'reasoning ', Channel::Reasoning),
            new TokenDelta('cue_3', 3, 'act_1', null, 'agent-test-agent', $at, 'final answer', Channel::Message),
            new TokenStop('cue_4', 4, 'act_1', null, 'agent-test-agent', $at, StopReason::EndOfTurn),
        ], Capabilities::empty());

        $loop = new Loop(new DefaultBuilder(), $provider, new SyncRuntimeFactory());

        $result = $loop(new ScopeStub(), new TestAgent(), new Activity\Config('act_1', Context::new(), 1));
        $records = $result->log->toArray();

        self::assertCount(1, $records);
        self::assertInstanceOf(Message::class, $records[0]);
        self::assertSame('final answer', $records[0]->text);
        self::assertCount(4, $result->stream->toArray());
        self::assertSame(
            [
                [Channel::Thinking, 'thinking '],
                [Channel::Reasoning, 'reasoning '],
                [Channel::Message, 'final answer'],
            ],
            self::streamedTokenDeltas($result->stream),
        );
    }

    #[Test]
    public function loopCanGrowLogAcrossThreeProviderInvocationsBeforeCompletion(): void
    {
        $at = new \DateTimeImmutable('2026-05-17T12:00:00Z');
        $provider = new SequencedProvider([
            [new TokenDelta('cue_1', 1, 'act_1', null, 'agent-test-agent', $at, 'first')],
            [new TokenDelta('cue_2', 2, 'act_1', null, 'agent-test-agent', $at, 'second')],
            [
                new TokenDelta('cue_3', 3, 'act_1', null, 'agent-test-agent', $at, 'final'),
                new TokenStop('cue_4', 4, 'act_1', null, 'agent-test-agent', $at, StopReason::EndOfTurn),
            ],
        ]);

        $loop = new Loop(new DefaultBuilder(), $provider, new SyncRuntimeFactory());

        $result = $loop(new ScopeStub(), new TestAgent(), new Activity\Config('act_1', Context::new(), 3));

        self::assertSame(Outcome::Complete, $result->outcome);
        self::assertSame(3, $result->invocations);
        self::assertCount(3, $result->log->toArray());
    }

    #[Test]
    public function loopThrowsWhenProviderNeverTerminatesWithinMaxInvocations(): void
    {
        $at = new \DateTimeImmutable('2026-05-17T12:00:00Z');
        $provider = new Provider([
            new TokenDelta('cue_1', 1, 'act_1', null, 'agent-test-agent', $at, 'still thinking'),
        ], Capabilities::empty());

        $loop = new Loop(new DefaultBuilder(), $provider, new SyncRuntimeFactory());

        $this->expectException(MaxInvocationsReached::class);

        $loop(new ScopeStub(), new TestAgent(), new Activity\Config('act_1', Context::new(), 2));
    }

    #[Test]
    public function terminalHookFailureMapsActivityToFailedState(): void
    {
        $provider = new Provider([], Capabilities::empty());
        $hook = new class implements StepHook {
            public function __invoke(TaskScope $scope, StepContext $context): StepHookResult
            {
                return StepHookResult::fail(new \RuntimeException('halted by hook'));
            }
        };

        $loop = new Loop(new DefaultBuilder(), $provider, new SyncRuntimeFactory(), hooks: [$hook]);

        $result = $loop(new ScopeStub(), new TestAgent(), new Activity\Config('act_1', Context::new(), 1));

        self::assertSame(Activity\State::Failed, $result->state);
        self::assertSame(Outcome::Failed, $result->outcome);
        self::assertInstanceOf(\RuntimeException::class, $result->error);
    }

    #[Test]
    public function hookFailureAfterCuePreservesThrowable(): void
    {
        $at = new \DateTimeImmutable('2026-05-17T12:00:00Z');
        $error = new \RuntimeException('cue rejected');
        $provider = new Provider([
            new TokenDelta('cue_1', 1, 'act_1', null, 'agent-test-agent', $at, 'partial'),
        ], Capabilities::empty());
        $hook = new FailAfterCueHook($error);
        $loop = new Loop(new DefaultBuilder(), $provider, new SyncRuntimeFactory(), hooks: [$hook]);

        $result = $loop(new ScopeStub(), new TestAgent(), new Activity\Config('act_1', Context::new(), 1));

        self::assertSame(Activity\State::Failed, $result->state);
        self::assertSame($error, $result->error);
    }

    #[Test]
    public function hookFailureAfterInvocationPreservesThrowable(): void
    {
        $at = new \DateTimeImmutable('2026-05-17T12:00:00Z');
        $error = new \RuntimeException('invocation rejected');
        $provider = new Provider([
            new TokenDelta('cue_1', 1, 'act_1', null, 'agent-test-agent', $at, 'done'),
            new TokenStop('cue_2', 2, 'act_1', null, 'agent-test-agent', $at, StopReason::EndOfTurn),
        ], Capabilities::empty());
        $hook = new FailAfterInvocationHook($error);
        $loop = new Loop(new DefaultBuilder(), $provider, new SyncRuntimeFactory(), hooks: [$hook]);

        $result = $loop(new ScopeStub(), new TestAgent(), new Activity\Config('act_1', Context::new(), 1));

        self::assertSame(Activity\State::Failed, $result->state);
        self::assertSame($error, $result->error);
    }

    #[Test]
    public function requestedEffectRequiringApprovalSuspendsActivity(): void
    {
        $at = new \DateTimeImmutable('2026-05-17T12:00:00Z');
        $provider = new Provider([
            new Requested(
                id: 'cue_1',
                sequence: 1,
                activityId: 'act_1',
                invocationId: null,
                agentId: 'agent-test-agent',
                at: $at,
                effectId: 'eff_1',
                kind: EffectKind::FileWrite,
                summary: 'write file',
                requiresApproval: true,
            ),
        ], Capabilities::empty());
        $loop = new Loop(new DefaultBuilder(), $provider, new SyncRuntimeFactory());

        $result = $loop(new ScopeStub(), new TestAgent(), new Activity\Config('act_1', Context::new(), 1));

        self::assertSame(Activity\State::Suspended, $result->state);
        self::assertSame(Outcome::WaitingForApproval, $result->outcome);
    }

    #[Test]
    public function toolCallPushesToolCallAndToolResultIntoConversationLog(): void
    {
        $at = new \DateTimeImmutable('2026-05-17T12:00:00Z');

        $provider = new SequencedProvider([
            [
                new Requested(
                    id: 'cue_1',
                    sequence: 1,
                    activityId: 'act_1',
                    invocationId: null,
                    agentId: 'agent-test-agent',
                    at: $at,
                    effectId: 'lookup_tool',
                    kind: EffectKind::Custom,
                    summary: 'look up data',
                ),
            ],
            [
                new TokenDelta('cue_2', 2, 'act_1', null, 'agent-test-agent', $at, 'done'),
                new TokenStop('cue_3', 3, 'act_1', null, 'agent-test-agent', $at, StopReason::EndOfTurn),
            ],
        ]);

        $registry = new \Phalanx\Agents\Tool\ToolRegistry();
        $registry->register('lookup_tool', LoopTestTool::class);

        $grantStore = new \Phalanx\Agents\Grant\MemoryGrantStore();
        $scope = new ScopeStub();
        $grantStore->remember($scope, \Phalanx\AiProviders\Grant::of(
            id: 'grant_loop',
            subject: 'agent-test-agent',
            allowedEffects: [EffectKind::Custom],
            scope: 'session',
            hazardCeiling: \Phalanx\AiProviders\Hazard::Critical,
        ));

        $dispatcher = new \Phalanx\Agents\Effect\Dispatcher(
            authorizer: new \Phalanx\AiProviders\Effect\Authorizer\Rules\Authorizer(),
            scorer: new \Phalanx\AiProviders\Hazard\Scorer\Rules\Scorer(),
            grantStore: $grantStore,
            toolRegistry: $registry,
            mcpRegistry: new \Phalanx\Agents\Mcp\McpRegistry(),
        );

        $loop = new Loop(new DefaultBuilder(), $provider, new SyncRuntimeFactory(), dispatcher: $dispatcher);

        $result = $loop($scope, new TestAgent(), new Activity\Config('act_1', Context::new(), 3));

        self::assertSame(Outcome::Complete, $result->outcome);
        self::assertSame(2, $result->invocations);

        $records = $result->log->toArray();
        self::assertCount(3, $records);

        self::assertInstanceOf(ToolCall::class, $records[0]);
        self::assertSame('lookup_tool', $records[0]->toolName);
        self::assertSame('lookup_tool', $records[0]->callId);

        self::assertInstanceOf(ToolResult::class, $records[1]);
        self::assertSame('lookup_tool', $records[1]->callId);
        self::assertStringContainsString('found', $records[1]->output);

        self::assertInstanceOf(Message::class, $records[2]);
        self::assertSame('done', $records[2]->text);
    }

    #[Test]
    public function requestedEffectWithoutDispatcherFailsFast(): void
    {
        $at = new \DateTimeImmutable('2026-05-17T12:00:00Z');
        $provider = new Provider([
            new Requested(
                id: 'cue_1',
                sequence: 1,
                activityId: 'act_1',
                invocationId: null,
                agentId: 'agent-test-agent',
                at: $at,
                effectId: 'eff_1',
                kind: EffectKind::FileRead,
                summary: 'read file',
            ),
        ], Capabilities::empty());
        $loop = new Loop(new DefaultBuilder(), $provider, new SyncRuntimeFactory());

        $result = $loop(new ScopeStub(), new TestAgent(), new Activity\Config('act_1', Context::new(), 1));

        self::assertSame(Activity\State::Failed, $result->state);
        self::assertSame(Outcome::Failed, $result->outcome);
        self::assertNotNull($result->error);
        self::assertStringContainsString('No effect dispatcher', $result->error->getMessage());
    }

    /**
     * @return list<array{Channel, string}>
     */
    private static function streamedTokenDeltas(Stream $stream): array
    {
        $deltas = [];

        foreach ($stream->toArray() as $cue) {
            if (!$cue instanceof TokenDelta) {
                continue;
            }

            $deltas[] = [$cue->channel, $cue->text];
        }

        return $deltas;
    }
}

final class SequencedProvider implements ProviderContract
{
    private int $calls = 0;

    /**
     * @param list<list<\Phalanx\AiProviders\Cue>> $scripts
     */
    public function __construct(private(set) array $scripts)
    {
    }

    public function perform(Invocation $invocation, Runtime $runtime): Stream
    {
        if ($this->calls >= count($this->scripts)) {
            throw new \RuntimeException(sprintf(
                'SequencedProvider: invocation %d exceeds %d scripted responses',
                $this->calls + 1,
                count($this->scripts),
            ));
        }

        $script = $this->scripts[$this->calls];
        $this->calls++;

        return Stream::from($script);
    }

    public function capabilities(): Capabilities
    {
        return Capabilities::empty();
    }
}

final class FailAfterCueHook implements StepHook
{
    public function __construct(
        private(set) \Throwable $error,
    ) {
    }

    public function __invoke(TaskScope $scope, StepContext $context): StepHookResult
    {
        return $context->cue !== null
            ? StepHookResult::fail($this->error)
            : StepHookResult::continue();
    }
}

final class LoopTestTool implements \Phalanx\Agents\Tool\Tool
{
    public function __invoke(TaskScope $scope, \Phalanx\Agents\Effect\Context $ctx): \Phalanx\Agents\Effect\Outcome
    {
        return \Phalanx\Agents\Effect\Outcome::routed(
            \Phalanx\Agents\Effect\Resolution::LocalTool,
            data: 'found',
        );
    }
}

final class FailAfterInvocationHook implements StepHook
{
    public function __construct(
        private(set) \Throwable $error,
    ) {
    }

    public function __invoke(TaskScope $scope, StepContext $context): StepHookResult
    {
        return $context->cue === null && $context->outcome !== Outcome::Continue
            ? StepHookResult::fail($this->error)
            : StepHookResult::continue();
    }
}
