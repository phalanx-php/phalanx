<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Template;

use Phalanx\Theatron\Input\InputMode;
use Phalanx\Theatron\Input\InputModeSlice;
use Phalanx\Theatron\Reactive\Tracker;
use Phalanx\Theatron\Template\AppStore;
use Phalanx\Theatron\Template\Slice\ActivitySlice;
use Phalanx\Theatron\Template\Slice\ActivityStatus;
use Phalanx\Theatron\Template\Slice\AgentRegistrySlice;
use Phalanx\Theatron\Template\Slice\AgentSummary;
use Phalanx\Theatron\Template\Slice\ConversationSlice;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AppStoreTest extends TestCase
{
    #[Test]
    public function propertyHookReadReturnsRegisteredSlice(): void
    {
        $store = new AppStore();

        self::assertInstanceOf(ConversationSlice::class, $store->conversation);
        self::assertInstanceOf(AgentRegistrySlice::class, $store->agents);
        self::assertInstanceOf(ActivitySlice::class, $store->activity);
    }

    #[Test]
    public function conversationPropertyHookWriteUpdatesSlice(): void
    {
        $store = new AppStore();
        $calls = 0;

        $store->subscribe(static function () use (&$calls): void {
            $calls++;
        });

        $store->conversation = new ConversationSlice()->addUserMessage('Advance the phalanx.');

        self::assertSame(1, $calls);
        self::assertCount(1, $store->conversation->messages);
        self::assertSame('Advance the phalanx.', $store->conversation->messages[0]->text);
    }

    #[Test]
    public function agentsPropertyHookWriteUpdatesSlice(): void
    {
        $store = new AppStore();
        $calls = 0;

        $store->subscribe(static function () use (&$calls): void {
            $calls++;
        });

        $agent = new AgentSummary(id: 'apollo-1', name: 'Apollo', capabilities: ['oracle', 'archery']);
        $store->agents = new AgentRegistrySlice()->register($agent);

        self::assertSame(1, $calls);
        self::assertCount(1, $store->agents->agents);
    }

    #[Test]
    public function activityPropertyHookWriteUpdatesSlice(): void
    {
        $store = new AppStore();
        $calls = 0;

        $store->subscribe(static function () use (&$calls): void {
            $calls++;
        });

        $store->activity = new ActivitySlice()->activityEnded(ActivityStatus::Completed);

        self::assertSame(1, $calls);
        self::assertSame(ActivityStatus::Completed, $store->activity->status);
    }

    #[Test]
    public function mutateAppliesTransformToConversation(): void
    {
        $store = new AppStore();

        $result = $store->mutate(
            ConversationSlice::class,
            static fn(ConversationSlice $s): ConversationSlice => $s->addUserMessage('Hold the line.'),
        );

        self::assertInstanceOf(ConversationSlice::class, $result);
        self::assertCount(1, $store->conversation->messages);
    }

    #[Test]
    public function allThreeSlicesWorkIndependently(): void
    {
        $store = new AppStore();

        $store->conversation = new ConversationSlice()->addUserMessage('Shields up.');
        $agent = new AgentSummary(id: 'ares-1', name: 'Ares', capabilities: ['battle']);
        $store->agents = new AgentRegistrySlice()->register($agent)->activate('ares-1');
        $store->activity = new ActivitySlice()->updateUsage(50, 100);

        self::assertCount(1, $store->conversation->messages);
        self::assertSame('ares-1', $store->agents->activeAgentId);
        self::assertSame(150, $store->activity->totalTokens);
    }

    #[Test]
    public function inputModePropertyHookReadsDefaultSlice(): void
    {
        $store = new AppStore();

        $slice = $store->inputMode;

        self::assertInstanceOf(InputModeSlice::class, $slice);
        self::assertSame(InputMode::Normal, $slice->mode);
        self::assertNull($slice->focusTarget);
    }

    #[Test]
    public function inputModePropertyHookWriteUpdatesSlice(): void
    {
        $store = new AppStore();
        $calls = 0;

        $store->subscribe(static function () use (&$calls): void {
            $calls++;
        });

        $store->inputMode = new InputModeSlice(InputMode::Insert, 'command-input');

        self::assertSame(1, $calls);
        self::assertSame(InputMode::Insert, $store->inputMode->mode);
        self::assertSame('command-input', $store->inputMode->focusTarget);
    }

    #[Test]
    public function inputModeSliceIsRegisteredIndependentlyOfOtherSlices(): void
    {
        $store = new AppStore();

        $store->inputMode = new InputModeSlice(InputMode::Insert, 'search');
        $store->conversation = new ConversationSlice()->addUserMessage('Advance.');

        self::assertSame(InputMode::Insert, $store->inputMode->mode);
        self::assertSame('search', $store->inputMode->focusTarget);
        self::assertCount(1, $store->conversation->messages);
    }

    #[Test]
    public function readRecordsTrackerAccess(): void
    {
        $store = new AppStore();
        $frame = Tracker::push();

        $_ = $store->conversation;

        $deps = Tracker::pop($frame);
        self::assertCount(1, $deps);
        self::assertSame($store, $deps[0]);
    }

    protected function tearDown(): void
    {
        while (Tracker::isTracking()) {
            Tracker::pop(0);
        }
    }
}
