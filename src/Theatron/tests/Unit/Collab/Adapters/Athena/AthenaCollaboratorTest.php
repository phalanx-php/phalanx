<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Collab\Adapters\Athena;

use Phalanx\Athena\Activity\Config;
use Phalanx\Athena\Activity\Result;
use Phalanx\Athena\Activity\State;
use Phalanx\Athena\Turn\Outcome;
use Phalanx\Cancellation\Cancelled;
use Phalanx\Panoply\Agent;
use Phalanx\Panoply\Capabilities;
use Phalanx\Panoply\Capability;
use Phalanx\Panoply\Context;
use Phalanx\Panoply\Conversation\Log;
use Phalanx\Panoply\Conversation\Record\Message;
use Phalanx\Panoply\Cue\Output\TokenDelta;
use Phalanx\Panoply\Cue\Output\TokenStop;
use Phalanx\Panoply\Cue\StopReason;
use Phalanx\Panoply\Effects;
use Phalanx\Panoply\Output;
use Phalanx\Panoply\Provider\Needs as ProviderNeeds;
use Phalanx\Panoply\Stream;
use Phalanx\Panoply\Transport\Needs as TransportNeeds;
use Phalanx\Scope\TaskScope;
use Phalanx\Theatron\Collab\Adapters\Athena\AthenaCollaborator;
use Phalanx\Theatron\Collab\Adapters\Athena\AthenaRunner;
use Phalanx\Theatron\Collab\Messages\Address;
use Phalanx\Theatron\Collab\Plans\Activity;
use Phalanx\Theatron\Collab\Plans\WorkItem;
use Phalanx\Theatron\Collab\Plans\WorkPlanItem;
use Phalanx\Theatron\Collab\Plans\WorkResultStatus;
use Phalanx\Theatron\Collab\WorkContext;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AthenaCollaboratorTest extends TestCase
{
    #[Test]
    public function workItemPromptBecomesTheInitialAthenaUserMessage(): void
    {
        $runner = new CapturingAthenaRunner(self::activityResult(State::Completed, Outcome::Complete, 'Patch is done.'));
        $collaborator = new AthenaCollaborator(self::agent('agent-a'), runner: $runner);
        $item = WorkPlanItem::pending(new WorkItem(Activity::Editing, 'Patch the failing test.', id: 'work_patch'));

        $result = $collaborator($item, new WorkContext($this->createStub(TaskScope::class)));

        self::assertSame(WorkResultStatus::Done, $result->status);
        self::assertSame('Patch is done.', $result->summary);
        self::assertSame('work_patch', $runner->config?->id);
        self::assertSame('Patch the failing test.', self::firstUserMessage($runner->log));
        self::assertSame('cue.output.token_delta', $result->payload['cues'][0]['type']);
    }

    #[Test]
    public function suspendedAthenaResultBecomesBlockedWork(): void
    {
        $runner = new CapturingAthenaRunner(self::activityResult(State::Suspended, Outcome::WaitingForApproval));
        $collaborator = new AthenaCollaborator(self::agent('agent-a'), runner: $runner);
        $item = WorkPlanItem::pending(new WorkItem(Activity::Editing, 'Patch code', id: 'work_patch'));

        $result = $collaborator($item, new WorkContext($this->createStub(TaskScope::class)));

        self::assertSame(WorkResultStatus::Blocked, $result->status);
        self::assertSame('Athena activity is waiting for approval.', $result->summary);
    }

    #[Test]
    public function failedAthenaResultBecomesFailedWork(): void
    {
        $error = new \RuntimeException('provider unavailable');
        $runner = new CapturingAthenaRunner(self::activityResult(State::Failed, Outcome::Failed, error: $error));
        $collaborator = new AthenaCollaborator(self::agent('agent-a'), runner: $runner);
        $item = WorkPlanItem::pending(new WorkItem(Activity::Editing, 'Patch code', id: 'work_patch'));

        $result = $collaborator($item, new WorkContext($this->createStub(TaskScope::class)));

        self::assertSame(WorkResultStatus::Failed, $result->status);
        self::assertSame($error, $result->error);
    }

    #[Test]
    public function cancelledAthenaResultPropagatesCancellation(): void
    {
        $runner = new CapturingAthenaRunner(self::activityResult(State::Cancelled, Outcome::Cancelled));
        $collaborator = new AthenaCollaborator(self::agent('agent-a'), runner: $runner);
        $item = WorkPlanItem::pending(new WorkItem(Activity::Editing, 'Patch code', id: 'work_patch'));

        $this->expectException(Cancelled::class);

        $collaborator($item, new WorkContext($this->createStub(TaskScope::class)));
    }

    #[Test]
    public function preferredParticipantMustMatchTheAgentIdentity(): void
    {
        $collaborator = new AthenaCollaborator(self::agent('agent-a'), runner: new CapturingAthenaRunner(self::activityResult()));

        self::assertTrue($collaborator->supports(
            WorkPlanItem::pending(new WorkItem(Activity::Editing, 'Patch code', preferredParticipant: Address::agent('agent-a'))),
            new WorkContext($this->createStub(TaskScope::class)),
        ));
        self::assertFalse($collaborator->supports(
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

        self::fail('Expected a user message in the Athena log.');
    }

    private static function agent(string $id): Agent
    {
        return new class ($id) implements Agent {
            public string $name { get => 'Test Agent'; }

            public Output $output { get => Output::text(); }

            public string $purpose { get => 'Handle the assigned work item.'; }

            public Context $context { get => Context::new(); }

            public Effects $effects { get => Effects::none(); }

            public ProviderNeeds $provider { get => ProviderNeeds::new()->require(Capability::Reasoning); }

            public Capabilities $capabilities { get => Capabilities::of(Capability::Reasoning); }

            public TransportNeeds $transport { get => TransportNeeds::new(); }

            public function __construct(private string $agentId)
            {
            }

            public string $id {
                get => $this->agentId;
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

final class CapturingAthenaRunner implements AthenaRunner
{
    public ?Config $config = null;

    public ?Log $log = null;

    public function __construct(private Result $result)
    {
    }

    public function __invoke(TaskScope $scope, Agent $agent, Config $config, ?Log $log = null): Result
    {
        $this->config = $config;
        $this->log = $log;

        return $this->result;
    }
}
