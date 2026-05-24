#!/usr/bin/env php
<?php

declare(strict_types=1);

$autoload = dirname(__DIR__) . '/vendor/autoload.php';

if (!is_file($autoload)) {
    $autoload = dirname(__DIR__, 3) . '/vendor/autoload.php';
}

require_once $autoload;

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

// Scripted Panoply cue stream that mimics a Zeus oracle response.
// No OpenSwoole, no Ollama — validates the reactive pipeline from cues to store.

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

echo "--- Theatron reactive pipeline demo ---\n";
echo sprintf("Messages: %d\n", count($conversation->messages));

foreach ($conversation->messages as $i => $message) {
    echo sprintf("  [%d] role=%s text=%s\n", $i, $message->role, $message->text);
}

echo sprintf("Streaming: %s\n", $conversation->isStreaming ? 'yes' : 'no');
echo sprintf("Activity status: %s\n", $activity->status->name);
echo sprintf("Input tokens: %d\n", $activity->inputTokens);
echo sprintf("Output tokens: %d\n", $activity->outputTokens);
echo sprintf("Total tokens: %d\n", $activity->totalTokens);
echo sprintf("Recorded requests: %d\n", count($store->requests->entries));
echo sprintf("Latest request: %s %s\n", $request->method, $request->path);
echo "--- done ---\n";
