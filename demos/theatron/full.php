<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Phalanx\Demos\Kit\DemoReport;
use Phalanx\Panoply\Cue\Activity\Completed as ActivityCompleted;
use Phalanx\Panoply\Cue\Activity\Started as ActivityStarted;
use Phalanx\Panoply\Cue\Output\Channel;
use Phalanx\Panoply\Cue\Output\TokenDelta;
use Phalanx\Panoply\Cue\Output\TokenStop;
use Phalanx\Panoply\Cue\StopReason;
use Phalanx\Panoply\Cue\Usage\Delta as UsageDelta;
use Phalanx\Panoply\Runtime;
use Phalanx\Panoply\Transport\Fake\Transport as FakeTransport;
use Phalanx\Panoply\Transport\Request;
use Phalanx\Theatron\Agent\LlmRequestRecordingTransport;
use Phalanx\Theatron\Agent\MockAgentExecutor;
use Phalanx\Theatron\Agent\StreamReactor;
use Phalanx\Theatron\Template\AppStore;
use Phalanx\Theatron\Template\Slice\ActivityStatus;

$report = new DemoReport('Theatron Reactive Pipeline');
$activityId = 'zeus-oracle-001';
$now = new DateTimeImmutable();

$cues = [
    new ActivityStarted(
        id: 'cue-001',
        sequence: 1,
        activityId: $activityId,
        invocationId: null,
        agentId: 'zeus',
        at: $now,
    ),
    new TokenDelta(
        id: 'cue-002',
        sequence: 2,
        activityId: $activityId,
        invocationId: null,
        agentId: 'zeus',
        at: $now,
        text: 'From Olympus, ',
        channel: Channel::Message,
    ),
    new TokenDelta(
        id: 'cue-003',
        sequence: 3,
        activityId: $activityId,
        invocationId: null,
        agentId: 'zeus',
        at: $now,
        text: 'the phalanx holds.',
        channel: Channel::Message,
    ),
    new TokenStop(
        id: 'cue-004',
        sequence: 4,
        activityId: $activityId,
        invocationId: null,
        agentId: 'zeus',
        at: $now,
        reason: StopReason::EndOfTurn,
        channel: Channel::Message,
    ),
    new UsageDelta(
        id: 'cue-005',
        sequence: 5,
        activityId: $activityId,
        invocationId: null,
        agentId: 'zeus',
        at: $now,
        inputTokens: 12,
        outputTokens: 5,
    ),
    new ActivityCompleted(
        id: 'cue-006',
        sequence: 6,
        activityId: $activityId,
        invocationId: null,
        agentId: 'zeus',
        at: $now,
    ),
];

$executor = new MockAgentExecutor($cues);
$store = new AppStore();

StreamReactor::consume($executor->send('Speak, Zeus.'), $store);

$requestTransport = new LlmRequestRecordingTransport(
    inner: new FakeTransport([
        'POST http://localhost/api/chat' => ['{"message":"from olympus"}'],
    ]),
    store: $store,
);

iterator_to_array($requestTransport->stream(
    Request::of(
        method: 'POST',
        url: 'http://localhost/api/chat',
        body: '{"model":"demo"}',
    ),
    new class implements Runtime {
        public function call(\Closure $work, ?string $waitReason = null): mixed
        {
            return $work();
        }

        public function isCancelled(): bool
        {
            return false;
        }

        public function throwIfCancelled(): void
        {
        }

        public function onCancel(\Closure $cleanup): void
        {
        }
    },
), false);

$conversation = $store->conversation;
$activity = $store->activity;
$request = $store->requests->focused();
$message = $conversation->messages[0] ?? null;

$report->record(
    'reactive stream writes one assistant message',
    count($conversation->messages) === 1
        && $message !== null
        && $message->role === 'assistant'
        && $message->text === 'From Olympus, the phalanx holds.',
);
$report->record('streaming flag is cleared', !$conversation->isStreaming);
$report->record('activity completes', $activity->status === ActivityStatus::Completed);
$report->record('usage accumulates input tokens', $activity->inputTokens === 12);
$report->record('usage accumulates output tokens', $activity->outputTokens === 5);
$report->record('usage totals tokens', $activity->totalTokens === 17);
$report->record('request recorder captures one request', count($store->requests->entries) === 1);
$report->record(
    'request recorder keeps focused method and path',
    $request !== null && $request->method === 'POST' && $request->path === '/api/chat',
);

exit($report->exitCode());
