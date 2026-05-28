<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Showcase;

use Phalanx\Theatron\Demos\Showcase\Slice\FeedMessage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FeedMessageTest extends TestCase
{
    #[Test]
    public function append_text_concatenates(): void
    {
        $msg = new FeedMessage(agentId: 'a', text: 'Hello', streaming: true);
        $updated = $msg->appendText(' world');

        self::assertSame('Hello world', $updated->text);
        self::assertTrue($updated->streaming);
        self::assertSame('Hello', $msg->text);
    }

    #[Test]
    public function finalize_clears_streaming_flag(): void
    {
        $msg = new FeedMessage(agentId: 'a', text: 'Done', streaming: true);
        $finalized = $msg->finalize();

        self::assertFalse($finalized->streaming);
        self::assertSame('Done', $finalized->text);
        self::assertTrue($msg->streaming);
    }

    #[Test]
    public function defaults_to_not_streaming(): void
    {
        $msg = new FeedMessage(agentId: 'a', text: 'hello');

        self::assertFalse($msg->streaming);
    }
}
