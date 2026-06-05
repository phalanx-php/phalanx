<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tests\Unit\Collab\Adapters\Agent;

use Phalanx\Agent\Activity\Config;
use Phalanx\Agent\Activity\Result;
use Phalanx\Agent\Activity\State;
use Phalanx\Agent\Hook\StepHook;
use Phalanx\Agent\Turn\Outcome;
use Phalanx\Cancellation\Cancelled;
use Phalanx\AiProviders\Agent as AiAgent;
use Phalanx\AiProviders\Capabilities;
use Phalanx\AiProviders\Capability;
use Phalanx\AiProviders\Context;
use Phalanx\AiProviders\Conversation\Log;
use Phalanx\AiProviders\Conversation\Record\Message;
use Phalanx\AiProviders\Cue\Output\TokenDelta;
use Phalanx\AiProviders\Cue\Output\TokenStop;
use Phalanx\AiProviders\Cue\StopReason;
use Phalanx\AiProviders\Effects;
use Phalanx\AiProviders\Output;
use Phalanx\AiProviders\Provider\Needs as ProviderNeeds;
use Phalanx\AiProviders\Stream;
use Phalanx\AiProviders\Transport\Needs as TransportNeeds;
use Phalanx\Scope\TaskScope;
use Phalanx\Tui\Collab\Adapters\Agent\AgentRunner;
use Phalanx\Tui\Collab\Adapters\Agent\AiAgentParticipant;
use Phalanx\Tui\Collab\Messages\Address;
use Phalanx\Tui\Collab\Plans\Activity;
use Phalanx\Tui\Collab\Plans\WorkItem;
use Phalanx\Tui\Collab\Plans\WorkPlanItem;
use Phalanx\Tui\Collab\Plans\WorkResultStatus;
use Phalanx\Tui\Collab\WorkContext;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AiAgentParticipantTest extends TestCase
{
    #[Test]
    public function workItemPromptBecomesTheInitialAgentUserMessage(): void
    {
        $runner = new CapturingAgentRunner(self::activityResult(State::Completed, Outcome::Complete, 'Patch is done.'));
        $participant = new AiAgentParticipant(self::agent('agent-a'), runner: $runner);
        $item = WorkPlanItem::pending(new WorkItem(Activity::Editing, 'Patch the failing test.', id: 'work_patch'));

        $result = $participant($item, new WorkContext($this->createStub(TaskScope::class)));

        self::assertSame(WorkResultStatus::Done, $result->status);
        self::assertSame('Patch is done.', $result->summary);
        self::assertSame('work_patch', $runner->config?->id);
        self::assertSame('Patch the failing test.', self::firstUserMessage($runner->log));
        self::assertSame('cue.output.token_delta', $result->payload['cues'][0]['type']);
    }

    #[Test]
    public function participantPassesAgentConfigScopeAndAgentThroughTheRunner(): void
    {
        $runner = new CapturingAgentRunner(self::activityResult());
        $scope = $this->createStub(TaskScope::class);
        $agent = self::agent('agent-a');
        $context = Context::new()->front(self::class);
        $hook = $this->createStub(StepHook::class);
        $participant = new AiAgentParticipant(
            agent: $agent,
            context: $context,
            maxInvocations: 5,
            timeoutSeconds: 2.5,
            hooks: [$hook],
            runner: $runner,
        );

        $participant(
            WorkPlanItem::pending(new WorkItem(Activity::Editing, 'Patch code', id: 'work_patch')),
            new WorkContext($scope),
        );

        self::assertSame($scope, $runner->scope);
        self::assertSame($agent, $runner->agent);
        self::assertInstanceOf(Config::class, $runner->config);
        self::assertSame('work_patch', $runner->config->id);
        self::assertSame($context, $runner->config->context);
        self::assertSame(5, $runner->config->maxInvocations);
        self::assertSame(2.5, $runner->config->timeoutSeconds);
        self::assertSame([$hook], $runner->config->hooks);
    }

    #[Test]
    public function participantUsesAgentContextByDefault(): void
    {
        $runner = new CapturingAgentRunner(self::activityResult());
        $agent = self::agent('agent-a');
        $participant = new AiAgentParticipant($agent, runner: $runner);

        $participant(
            WorkPlanItem::pending(new WorkItem(Activity::Editing, 'Patch code', id: 'work_patch')),
            new WorkContext($this->createStub(TaskScope::class)),
        );

        self::assertSame($agent->context->toCanonical(), $runner->config?->context->toCanonical());
    }


    #[Test]
    public function suspendedAgentResultBecomesBlockedWork(): void
    {
        $runner = new CapturingAgentRunner(self::activityResult(State::Suspended, Outcome::WaitingForApproval));
        $participant = new AiAgentParticipant(self::agent('agent-a'), runner: $runner);
        $item = WorkPlanItem::pending(new WorkItem(Activity::Editing, 'Patch code', id: 'work_patch'));

        $result = $participant($item, new WorkContext($this->createStub(TaskScope::class)));

        self::assertSame(WorkResultStatus::Blocked, $result->status);
        self::assertSame('Agent activity is waiting for approval.', $result->summary);
    }

    #[Test]
    public function failedAgentResultBecomesFailedWork(): void
    {
        $error = new \RuntimeException('provider unavailable');
        $runner = new CapturingAgentRunner(self::activityResult(State::Failed, Outcome::Failed, error: $error));
        $participant = new AiAgentParticipant(self::agent('agent-a'), runner: $runner);
        $item = WorkPlanItem::pending(new WorkItem(Activity::Editing, 'Patch code', id: 'work_patch'));

        $result = $participant($item, new WorkContext($this->createStub(TaskScope::class)));

        self::assertSame(WorkResultStatus::Failed, $result->status);
        self::assertSame($error, $result->error);
    }

    #[Test]
    public function cancelledAgentResultPropagatesCancellation(): void
    {
        $runner = new CapturingAgentRunner(self::activityResult(State::Cancelled, Outcome::Cancelled));
        $participant = new AiAgentParticipant(self::agent('agent-a'), runner: $runner);
        $item = WorkPlanItem::pending(new WorkItem(Activity::Editing, 'Patch code', id: 'work_patch'));

        $this->expectException(Cancelled::class);

        $participant($item, new WorkContext($this->createStub(TaskScope::class)));
    }

    #[Test]
    public function preferredParticipantMustMatchTheAgentIdentity(): void
    {
        $participant = new AiAgentParticipant(self::agent('agent-a'), runner: new CapturingAgentRunner(self::activityResult()));

        self::assertTrue($participant->supports(
            WorkPlanItem::pending(new WorkItem(Activity::Editing, 'Patch code', preferredParticipant: Address::agent('agent-a'))),
            new WorkContext($this->createStub(TaskScope::class)),
        ));
        self::assertFalse($participant->supports(
            WorkPlanItem::pending(new WorkItem(Activity::Editing, 'Patch code', preferredParticipant: Address::agent('agent-b'))),
            new WorkContext($this->createStub(TaskScope::class)),
        ));
    }

    private static function firstUserMessage(?Log $log): string
    {
        self::assertNotNull($log);

        foreach ($log->toArray() as $record) {
            if ($record instanceof Message && $record->role === 'user') {
                return $record->text;
            }
        }

        self::fail('Expected a user message in the Agent log.');
    }

    private static function agent(string $id): AiAgent
    {
        return new class ($id) implements AiAgent {
            public string $name { get => 'Test Agent'; }

            public string $id {
                get => $this->agentId;
            }

            public Output $output { get => Output::text(); }

            public string $purpose { get => 'Handle the assigned work item.'; }

            public Context $context { get => Context::new(); }

            public Effects $effects { get => Effects::none(); }

            public ProviderNeeds $provider { get => ProviderNeeds::new()->require(Capability::Reasoning); }

            public Capabilities $capabilities { get => Capabilities::of(Capability::Reasoning); }

            public TransportNeeds $transport { get => TransportNeeds::new(); }

            private string $agentId;

            public function __construct(string $agentId)
            {
                $this->agentId = $agentId;
            }
        };
    }

    private static function activityResult(
        State $state = State::Completed,
        Outcome $outcome = Outcome::Complete,
        ?string $assistantText = null,
        ?\Throwable $error = null,
    ): Result {
        $at = new \DateTimeImmutable('2026-05-31T12:00:00Z');
        $records = [];
        if ($assistantText !== null) {
            $records[] = new Message('msg_assistant', 1, $at, role: 'assistant', text: $assistantText);
        }

        return new Result(
            activityId: 'work_patch',
            state: $state,
            outcome: $outcome,
            log: Log::from($records),
            invocations: 1,
            error: $error,
            stream: Stream::from([
                new TokenDelta('cue_1', 1, 'work_patch', null, 'agent-a', $at, 'Patch'),
                new TokenStop('cue_2', 2, 'work_patch', null, 'agent-a', $at, StopReason::EndOfTurn),
            ]),
        );
    }
}

final class CapturingAgentRunner implements AgentRunner
{
    public ?TaskScope $scope = null;

    public ?AiAgent $agent = null;

    public ?Config $config = null;

    public ?Log $log = null;

    public function __construct(private Result $result)
    {
    }

    public function __invoke(TaskScope $scope, AiAgent $agent, Config $config, ?Log $log = null): Result
    {
        $this->scope = $scope;
        $this->agent = $agent;
        $this->config = $config;
        $this->log = $log;

        return $this->result;
    }
}
