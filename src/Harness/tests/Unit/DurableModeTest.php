<?php

declare(strict_types=1);

namespace Phalanx\Harness\Tests\Unit;

use DateTimeImmutable;
use Phalanx\Agora\Harness\HarnessEvent;
use Phalanx\Agora\Harness\ProjectionSet;
use Phalanx\Agora\Harness\Replay\ReplaySession;
use Phalanx\Harness\Agent\AgentRuntime;
use Phalanx\Harness\AgoraServiceBundle;
use Phalanx\Harness\Harness;
use Phalanx\Harness\HarnessConfig;
use Phalanx\Harness\HarnessMode;
use Phalanx\Harness\Replay\TheatronReplayHydrator;
use Phalanx\Harness\Tests\Support\RecordingAgentExecutor;
use Phalanx\Harness\Tests\Support\RecordingCueRecorder;
use Phalanx\Harness\Tests\Support\RecordingTaskScope;
use Phalanx\Harness\Ui\AppStore;
use Phalanx\Harness\Ui\Slices\ActivityStatus;
use Phalanx\Harness\Ui\Slices\ConversationTurnStatus;
use Phalanx\Panoply\Cue\Activity\Completed as ActivityCompleted;
use Phalanx\Panoply\Cue\Activity\Failed as ActivityFailed;
use Phalanx\Panoply\Cue\Activity\Started as ActivityStarted;
use Phalanx\Panoply\Cue\Output\TokenDelta;
use Phalanx\Panoply\Cue\Output\TokenStop;
use Phalanx\Panoply\Cue\StopReason;
use Phalanx\Surreal\SurrealBundle;
use Phalanx\Theatron\Stage\StageConfig;
use Phalanx\Themis\IssueLevel;
use Phalanx\Themis\ValidationContext;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('harness')]
final class DurableModeTest extends TestCase
{
    #[Test]
    public function agentRuntimeWithRecorderPersistsCuesDuringSend(): void
    {
        $at = new DateTimeImmutable();
        $recorder = new RecordingCueRecorder();
        $sessionId = 'session-leonidas';
        $started = new ActivityStarted('cue-start', 1, 'activity-marathon', null, null, $at);
        $completed = new ActivityCompleted('cue-done', 2, 'activity-marathon', null, null, $at);
        $executor = new RecordingAgentExecutor(sendCues: [$started, $completed]);
        $store = new AppStore();
        $scope = new RecordingTaskScope();

        $runtime = new AgentRuntime($store, $executor, $recorder, $sessionId);
        $runtime->send($scope, 'hold the pass');

        self::assertCount(2, $recorder->recorded);
        self::assertSame($started, $recorder->recorded[0]['cue']);
        self::assertSame($sessionId, $recorder->recorded[0]['sessionId']);
        self::assertNull($recorder->recorded[0]['turnId']);
        self::assertSame($completed, $recorder->recorded[1]['cue']);
        self::assertSame($sessionId, $recorder->recorded[1]['sessionId']);
    }

    #[Test]
    public function agentRuntimeWithoutRecorderWorksInEphemeralMode(): void
    {
        $at = new DateTimeImmutable();
        $started = new ActivityStarted('cue-start', 1, 'activity-thermopylae', null, null, $at);
        $executor = new RecordingAgentExecutor(sendCues: [$started]);
        $store = new AppStore();
        $scope = new RecordingTaskScope();

        $runtime = new AgentRuntime($store, $executor);
        $runtime->send($scope, 'advance');

        self::assertSame(['advance'], $executor->sentMessages);
    }

    #[Test]
    public function agentRuntimeWithRecorderButNoSessionIdSkipsRecording(): void
    {
        $at = new DateTimeImmutable();
        $recorder = new RecordingCueRecorder();
        $started = new ActivityStarted('cue-start', 1, 'activity-sparta', null, null, $at);
        $executor = new RecordingAgentExecutor(sendCues: [$started]);
        $store = new AppStore();
        $scope = new RecordingTaskScope();

        $runtime = new AgentRuntime($store, $executor, $recorder, null);
        $runtime->send($scope, 'advance');

        self::assertCount(0, $recorder->recorded);
        self::assertSame(['advance'], $executor->sentMessages);
    }

    #[Test]
    public function harnessBuilderDurableRegistersAgoraBundles(): void
    {
        $builder = Harness::app(['APP_ENV' => 'test'])->durable()->stageConfig(self::inlineStageConfig());
        $builder->build();

        $providers = $builder->registeredProviders();

        self::assertCount(5, $providers);
        self::assertNotNull(array_find(
            $providers,
            static fn(mixed $p): bool => $p instanceof SurrealBundle,
        ));
        self::assertNotNull(array_find(
            $providers,
            static fn(mixed $p): bool => $p instanceof AgoraServiceBundle,
        ));
    }

    #[Test]
    public function harnessBuilderEphemeralOmitsAgoraBundles(): void
    {
        $builder = Harness::app(['APP_ENV' => 'test'])->stageConfig(self::inlineStageConfig());
        $builder->build();

        $providers = $builder->registeredProviders();

        self::assertCount(3, $providers);
        self::assertNull(array_find(
            $providers,
            static fn(mixed $p): bool => $p instanceof SurrealBundle,
        ));
        self::assertNull(array_find(
            $providers,
            static fn(mixed $p): bool => $p instanceof AgoraServiceBundle,
        ));
    }

    #[Test]
    public function harnessConfigValidatesSessionIdWithoutDurable(): void
    {
        $config = new HarnessConfig(durable: false, sessionId: 'some-id');
        $issues = $config->validate(new ValidationContext());

        self::assertCount(1, $issues);
        self::assertSame(IssueLevel::Warning, $issues[0]->level);
        self::assertSame('harness.session-id-without-durable', $issues[0]->code);
    }

    #[Test]
    public function harnessConfigDurableModeProperty(): void
    {
        $durable = new HarnessConfig(durable: true);
        self::assertSame(HarnessMode::Durable, $durable->mode);

        $ephemeral = new HarnessConfig(durable: false);
        self::assertSame(HarnessMode::Ephemeral, $ephemeral->mode);
    }

    #[Test]
    public function agentRuntimeTurnCycleProducesConversationEntry(): void
    {
        $at = new DateTimeImmutable();
        $activityId = 'activity-olympus';
        $started = new ActivityStarted('cue-start', 1, $activityId, null, null, $at);
        $delta1 = new TokenDelta('cue-delta-1', 2, $activityId, null, null, $at, 'Shields up, ');
        $delta2 = new TokenDelta('cue-delta-2', 3, $activityId, null, null, $at, 'hoplites.');
        $stop = new TokenStop('cue-stop', 4, $activityId, null, null, $at, StopReason::EndOfTurn);
        $completed = new ActivityCompleted('cue-done', 5, $activityId, null, null, $at);
        $executor = new RecordingAgentExecutor(sendCues: [$started, $delta1, $delta2, $stop, $completed]);
        $store = new AppStore();
        $scope = new RecordingTaskScope();

        $store->conversation = $store->conversation->addUserMessage('form the phalanx');
        $runtime = new AgentRuntime($store, $executor);
        $runtime->send($scope, 'form the phalanx');

        self::assertSame(['form the phalanx'], $executor->sentMessages);
        self::assertSame(ActivityStatus::Completed, $store->activity->status);
        self::assertFalse($store->conversation->isStreaming);

        $messages = $store->conversation->messages;
        $assistantMessages = array_values(array_filter($messages, static fn($m) => $m->role === 'assistant'));
        self::assertNotEmpty($assistantMessages);
        self::assertSame('Shields up, hoplites.', $assistantMessages[count($assistantMessages) - 1]->text);
        self::assertTrue($assistantMessages[count($assistantMessages) - 1]->complete);

        $turns = $store->conversation->turns;
        self::assertGreaterThanOrEqual(1, count($turns));
        self::assertSame(ConversationTurnStatus::Completed, $turns[count($turns) - 1]->status);
    }

    #[Test]
    public function agentRuntimeTurnCycleWithFailureProducesFailedStatus(): void
    {
        $at = new DateTimeImmutable();
        $activityId = 'activity-plataea';
        $started = new ActivityStarted('cue-start', 1, $activityId, null, null, $at);
        $failed = new ActivityFailed('cue-fail', 2, $activityId, null, null, $at, 'Provider unreachable');
        $executor = new RecordingAgentExecutor(sendCues: [$started, $failed]);
        $store = new AppStore();
        $scope = new RecordingTaskScope();

        $runtime = new AgentRuntime($store, $executor);
        $runtime->send($scope, 'advance on plataea');

        self::assertSame(ActivityStatus::Failed, $store->activity->status);
        self::assertFalse($store->conversation->isStreaming);

        $turns = $store->conversation->turns;
        self::assertGreaterThanOrEqual(1, count($turns));
        self::assertSame(ConversationTurnStatus::Failed, $turns[count($turns) - 1]->status);
    }

    #[Test]
    public function replayHydrationReconstructsConversationFromEvents(): void
    {
        $at = new DateTimeImmutable('2026-05-26T10:00:00+00:00');
        $sessionId = 'session-pericles';
        $turnId = 'turn-demosthenes';
        $activityId = 'activity-agora';

        $started = new ActivityStarted('cue-start', 1, $activityId, null, null, $at);
        $delta = new TokenDelta('cue-delta', 2, $activityId, null, null, $at, 'The assembly has spoken.');
        $stop = new TokenStop('cue-stop', 3, $activityId, null, null, $at, StopReason::EndOfTurn);
        $completed = new ActivityCompleted('cue-done', 4, $activityId, null, null, $at);

        $events = [
            HarnessEvent::fromCue($started, $sessionId, 1, $turnId),
            HarnessEvent::fromCue($delta, $sessionId, 2, $turnId),
            HarnessEvent::fromCue($stop, $sessionId, 3, $turnId),
            HarnessEvent::fromCue($completed, $sessionId, 4, $turnId),
        ];

        $session = new ReplaySession(
            sessionId: $sessionId,
            projections: ProjectionSet::empty($sessionId),
            events: $events,
            checkpointSequence: 0,
        );

        $store = new AppStore();
        $hydrator = new TheatronReplayHydrator();
        $hydrator->hydrate($store, $session);

        self::assertSame(ActivityStatus::Completed, $store->activity->status);
        self::assertNotEmpty($store->conversation->turns);

        $turn = $store->conversation->turns[0];
        self::assertSame($turnId, $turn->id);
        self::assertSame(ConversationTurnStatus::Completed, $turn->status);

        $assistantMessages = array_values(array_filter(
            $store->conversation->messages,
            static fn($m) => $m->role === 'assistant' && $m->channel === 'message',
        ));
        self::assertNotEmpty($assistantMessages);
        self::assertSame('The assembly has spoken.', $assistantMessages[0]->text);
        self::assertTrue($assistantMessages[0]->complete);
    }

    #[Test]
    public function replayHydrationWithNoEventsProducesCleanStore(): void
    {
        $session = new ReplaySession(
            sessionId: 'session-solon',
            projections: ProjectionSet::empty('session-solon'),
            events: [],
            checkpointSequence: 0,
        );

        $store = new AppStore();
        $hydrator = new TheatronReplayHydrator();
        $hydrator->hydrate($store, $session);

        self::assertEmpty($store->conversation->turns);
        self::assertEmpty($store->conversation->messages);
        self::assertFalse($store->conversation->isStreaming);
    }

    private static function inlineStageConfig(): StageConfig
    {
        $stream = fopen('php://memory', 'w+');
        assert(is_resource($stream));

        return new StageConfig(
            handleInput: false,
            defaultExitHandler: false,
            stream: $stream,
            env: ['COLUMNS' => '80', 'LINES' => '24'],
        );
    }
}
