<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Capstone;

use Phalanx\Theatron\Demos\Capstone\Slice\ConversationMessage;
use Phalanx\Theatron\Demos\Capstone\Slice\ConversationSlice;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ConversationSliceTest extends TestCase
{
    #[Test]
    public function append_adds_message(): void
    {
        $slice = new ConversationSlice();
        $msg = new ConversationMessage(from: 'agent-a', body: 'hello', timestamp: 1.0);

        $updated = $slice->append($msg);

        self::assertCount(1, $updated->messages);
        self::assertSame('hello', $updated->messages[0]->body);
        self::assertCount(0, $slice->messages);
    }

    #[Test]
    public function append_caps_at_fifty_messages(): void
    {
        $messages = [];

        for ($i = 0; $i < 50; $i++) {
            $messages[] = new ConversationMessage(from: 'a', body: "msg-{$i}", timestamp: (float) $i);
        }

        $slice = new ConversationSlice($messages);
        $updated = $slice->append(new ConversationMessage(from: 'a', body: 'overflow', timestamp: 51.0));

        self::assertCount(50, $updated->messages);
        self::assertSame('msg-1', $updated->messages[0]->body);
        self::assertSame('overflow', $updated->messages[49]->body);
    }

    #[Test]
    public function slice_key_is_capstone_conversation(): void
    {
        self::assertSame('capstone.conversation', (new ConversationSlice())->key);
    }
}
