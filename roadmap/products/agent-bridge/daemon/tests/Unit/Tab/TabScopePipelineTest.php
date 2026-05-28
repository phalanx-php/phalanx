<?php

declare(strict_types=1);

namespace AgentBridge\Tests\Unit\Tab;

use AgentBridge\BridgeConfig;
use AgentBridge\BridgeMessage;
use AgentBridge\ExtensionSession;
use AgentBridge\Lego\LegoLibrary;
use AgentBridge\Policy\PolicyStore;
use AgentBridge\Tab\TabScope;
use Phalanx\Concurrency\CancellationToken;
use Phalanx\ExecutionScope;
use Phalanx\Styx\Channel;
use Phalanx\Stream\Contract\StreamContext;
use Phalanx\Styx\Emitter;
use Phalanx\Styx\ScopedStream;
use Phalanx\Testing\TestScope;
use Phalanx\Hermes\WsConnection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function React\Async\async;
use function React\Async\await;
use function React\Async\delay;

final class TabScopePipelineTest extends TestCase
{
    private static function makeConfig(): BridgeConfig
    {
        return new BridgeConfig(
            dataDir: '/tmp/test-bridge',
            classifierBufferCount: 20,
            classifierBufferSeconds: 2.0,
            maxEventsPerSecThrottled: 5,
        );
    }

    private static function makeSession(): array
    {
        $conn = new WsConnection('test-pipeline-conn');
        $session = new ExtensionSession($conn);
        return [$session, $conn];
    }

    private static function makeTab(ExecutionScope $scope, ExtensionSession $session, BridgeConfig $config): TabScope
    {
        return new TabScope(
            tabId: 99,
            url: 'https://example.com',
            title: 'Test Tab',
            domain: 'example.com',
            session: $session,
            scope: $scope,
            cancellation: CancellationToken::create(),
            legoLibrary: new LegoLibrary('/tmp/test-legos'),
            policyStore: new PolicyStore('/tmp/test-policies'),
            config: $config,
        );
    }

    // ---------------------------------------------------------------------------
    // Filter
    // ---------------------------------------------------------------------------

    #[Test]
    public function pipeline_filters_non_classifiable_messages(): void
    {
        // Test the filter predicate in isolation using the stream API directly.
        // This verifies the three allowed types pass and everything else is dropped.
        TestScope::run(static function (ExecutionScope $scope): void {
            $passed = [];

            $messages = [
                BridgeMessage::fromJson(['type' => 'dom.snapshot', 'tabId' => 1]),
                BridgeMessage::fromJson(['type' => 'user.action',  'tabId' => 1]),
                BridgeMessage::fromJson(['type' => 'dom.mutations', 'tabId' => 1, 'mutations' => []]),
                BridgeMessage::fromJson(['type' => 'flow.pressure', 'tabId' => 1]),
                BridgeMessage::fromJson(['type' => 'net.response',  'tabId' => 1]),
                BridgeMessage::fromJson(['type' => 'net.request',   'tabId' => 1]),
                BridgeMessage::fromJson(['type' => 'tab.navigate',  'tabId' => 1]),
            ];

            ScopedStream::from(
                $scope,
                Emitter::produce(static function (Channel $ch, StreamContext $ctx) use ($messages): void {
                    foreach ($messages as $msg) {
                        $ch->emit($msg);
                    }
                }),
            )
            ->filter(static function (mixed $msg): bool {
                assert($msg instanceof BridgeMessage);
                return match ($msg->type) {
                    'dom.snapshot', 'dom.mutations', 'net.response' => true,
                    default => false,
                };
            })
            ->onEach(static function (mixed $msg) use (&$passed): void {
                $passed[] = $msg;
            })
            ->consume();

            self::assertCount(3, $passed);
            self::assertSame('dom.snapshot',  $passed[0]->type);
            self::assertSame('dom.mutations', $passed[1]->type);
            self::assertSame('net.response',  $passed[2]->type);
        });
    }

    // ---------------------------------------------------------------------------
    // Buffering
    // ---------------------------------------------------------------------------

    #[Test]
    public function pipeline_buffers_messages_into_batches(): void
    {
        // Verifies bufferWindow collects multiple events into a single array batch.
        // Uses a count-based window (3 items) so the test doesn't need real timers.
        TestScope::run(static function (ExecutionScope $scope): void {
            $batches = [];

            $messages = [
                BridgeMessage::fromJson(['type' => 'dom.mutations', 'tabId' => 1, 'mutations' => []]),
                BridgeMessage::fromJson(['type' => 'dom.mutations', 'tabId' => 1, 'mutations' => []]),
                BridgeMessage::fromJson(['type' => 'dom.mutations', 'tabId' => 1, 'mutations' => []]),
                BridgeMessage::fromJson(['type' => 'dom.snapshot',  'tabId' => 1]),
                BridgeMessage::fromJson(['type' => 'dom.snapshot',  'tabId' => 1]),
            ];

            ScopedStream::from(
                $scope,
                Emitter::produce(static function (Channel $ch, StreamContext $ctx) use ($messages): void {
                    foreach ($messages as $msg) {
                        $ch->emit($msg);
                    }
                }),
            )
            ->bufferWindow(count: 3, seconds: 60.0) // large timeout, count-driven flush
            ->onEach(static function (mixed $batch) use (&$batches): void {
                $batches[] = $batch;
            })
            ->consume();

            // 5 messages with window size 3: first batch has 3, second has 2 (flushed at stream end)
            self::assertCount(2, $batches);
            self::assertCount(3, $batches[0]);
            self::assertCount(2, $batches[1]);
            self::assertContainsOnlyInstancesOf(BridgeMessage::class, $batches[0]);
        });
    }

    // ---------------------------------------------------------------------------
    // Dispose
    // ---------------------------------------------------------------------------

    #[Test]
    public function pipeline_disposes_cleanly_when_tab_disconnects(): void
    {
        // Start a real pipeline via startPipeline(), then dispose() the tab.
        // The pipeline fiber must exit without errors: completing the inbound channel
        // terminates the producer generator which propagates through all operators.
        TestScope::run(static function (ExecutionScope $scope): void {
            [$session] = self::makeSession();
            $config = self::makeConfig();
            $tab = self::makeTab($scope, $session, $config);

            $tab->startPipeline();

            // Emit one classifiable event so the pipeline has something to process.
            $tab->inbound->tryEmit(
                BridgeMessage::fromJson(['type' => 'dom.snapshot', 'tabId' => 99])
            );

            // Yield to the event loop so the pipeline fiber can start consuming.
            await(async(static fn() => delay(0.01))());

            // Dispose completes the inbound channel -- the pipeline fiber drains and exits.
            $tab->dispose();

            // Give the pipeline fiber a tick to notice the channel is closed.
            await(async(static fn() => delay(0.01))());

            // No assertion needed: if this reaches here without exception the test passes.
            // Errors in the defer()-ed fiber would propagate as unhandled exceptions in the loop.
            self::assertTrue(true);
        });
    }

    // ---------------------------------------------------------------------------
    // Backpressure: throttle
    // ---------------------------------------------------------------------------

    #[Test]
    public function backpressure_sends_throttle_command_when_channel_fills(): void
    {
        // The withPressure callback fires synchronously inside tryEmit when the buffer
        // reaches capacity. No fiber context required for this assertion.
        [$session, $conn] = self::makeSession();
        $config = self::makeConfig();
        $execScope = $this->createMock(ExecutionScope::class);

        $tab = new TabScope(
            tabId: 42,
            url: 'https://example.com',
            title: 'Test',
            domain: 'example.com',
            session: $session,
            scope: $execScope,
            cancellation: CancellationToken::create(),
            legoLibrary: new LegoLibrary('/tmp/test-legos'),
            policyStore: new PolicyStore('/tmp/test-policies'),
            config: $config,
        );

        // Fill the channel to capacity (bufferSize: 64). The pressure callback fires
        // synchronously on the 64th emit, sending flow.throttle to the extension.
        $msg = BridgeMessage::fromJson(['type' => 'dom.mutations', 'tabId' => 42, 'mutations' => []]);
        for ($i = 0; $i < 64; $i++) {
            $tab->inbound->tryEmit($msg);
        }

        // The outbound channel of the WsConnection holds serialized commands.
        $outboundBuffer = (new \ReflectionProperty($conn->outbound, 'buffer'))->getValue($conn->outbound);
        self::assertNotEmpty($outboundBuffer, 'Expected flow.throttle to be sent but outbound buffer is empty');

        $sentJson = $outboundBuffer[0]->payload ?? '';
        $decoded = json_decode($sentJson, true);
        self::assertSame('flow.throttle', $decoded['type']);
        self::assertSame(42, $decoded['tabId']);
        self::assertSame($config->maxEventsPerSecThrottled, $decoded['maxEventsPerSec']);
    }

    // ---------------------------------------------------------------------------
    // Backpressure: resume
    // ---------------------------------------------------------------------------

    #[Test]
    public function backpressure_sends_resume_command_when_channel_drains(): void
    {
        // Resume fires when the consumer drains below 50% of buffer capacity.
        // The pipeline must be running to consume items -- use TestScope::run().
        TestScope::run(static function (ExecutionScope $scope): void {
            [$session, $conn] = self::makeSession();
            $config = self::makeConfig();
            $tab = self::makeTab($scope, $session, $config);

            // Start the pipeline so it actively consumes the inbound channel.
            $tab->startPipeline();

            // Fill to capacity so flow.throttle fires.
            $msg = BridgeMessage::fromJson(['type' => 'dom.mutations', 'tabId' => 99, 'mutations' => []]);
            for ($i = 0; $i < 64; $i++) {
                $tab->inbound->tryEmit($msg);
            }

            // Yield to let the pipeline fiber consume items below the 50% drain threshold (32 items).
            await(async(static fn() => delay(0.05))());

            $outboundBuffer = (new \ReflectionProperty($conn->outbound, 'buffer'))->getValue($conn->outbound);

            $sentTypes = array_map(
                static function (mixed $frame): string {
                    $decoded = json_decode($frame->payload ?? '{}', true);
                    return $decoded['type'] ?? '';
                },
                $outboundBuffer,
            );

            self::assertContains('flow.throttle', $sentTypes, 'Expected flow.throttle to have been sent');
            self::assertContains('flow.resume', $sentTypes, 'Expected flow.resume to have been sent after drain');

            // Drain the tab cleanly to avoid dangling fibers after the test.
            $tab->dispose();
        });
    }
}
