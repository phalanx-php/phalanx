<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Showcase;

use Phalanx\Theatron\Demos\Showcase\Slice\DispatchFeedSlice;
use Phalanx\Theatron\Demos\Showcase\Slice\FeedMessage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DispatchFeedSliceTest extends TestCase
{
    #[Test]
    public function empty_feed_returns_no_messages(): void
    {
        $feed = new DispatchFeedSlice();

        self::assertSame([], $feed->allMessages());
    }

    #[Test]
    public function append_adds_finalized_message(): void
    {
        $msg = new FeedMessage(agentId: 'a', text: 'hello');
        $feed = (new DispatchFeedSlice())->append($msg);

        $all = $feed->allMessages();
        self::assertCount(1, $all);
        self::assertSame('hello', $all[0]->text);
    }

    #[Test]
    public function append_token_accumulates_in_streaming_map(): void
    {
        $feed = (new DispatchFeedSlice())
            ->appendToken('a', 'Hello')
            ->appendToken('a', ' world');

        $all = $feed->allMessages();
        self::assertCount(1, $all);
        self::assertSame('Hello world', $all[0]->text);
        self::assertTrue($all[0]->streaming);
    }

    #[Test]
    public function concurrent_agents_dont_interleave(): void
    {
        $feed = (new DispatchFeedSlice())
            ->appendToken('a', 'alpha-1')
            ->appendToken('b', 'beta-1')
            ->appendToken('a', ' alpha-2')
            ->appendToken('b', ' beta-2');

        $all = $feed->allMessages();
        self::assertCount(2, $all);

        $texts = array_map(static fn(FeedMessage $m): string => $m->text, $all);
        sort($texts);

        self::assertSame(['alpha-1 alpha-2', 'beta-1 beta-2'], $texts);
    }

    #[Test]
    public function finalize_stream_moves_to_messages(): void
    {
        $feed = (new DispatchFeedSlice())
            ->appendToken('a', 'streaming text')
            ->finalizeStream('a');

        $all = $feed->allMessages();
        self::assertCount(1, $all);
        self::assertFalse($all[0]->streaming);
        self::assertSame('streaming text', $all[0]->text);
    }

    #[Test]
    public function finalize_unknown_agent_is_noop(): void
    {
        $feed = (new DispatchFeedSlice())->finalizeStream('nonexistent');

        self::assertSame([], $feed->allMessages());
    }

    #[Test]
    public function all_messages_combines_finalized_and_streaming(): void
    {
        $msg = new FeedMessage(agentId: 'x', text: 'old');
        $feed = (new DispatchFeedSlice())
            ->append($msg)
            ->appendToken('a', 'live');

        $all = $feed->allMessages();
        self::assertCount(2, $all);
        self::assertSame('old', $all[0]->text);
        self::assertSame('live', $all[1]->text);
    }

    #[Test]
    public function slice_key_is_showcase_feed(): void
    {
        self::assertSame('showcase.feed', (new DispatchFeedSlice())->key);
    }
}
