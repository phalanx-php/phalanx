<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Template\Slice;

use Phalanx\Theatron\Template\Slice\ConversationSlice;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ConversationSliceTest extends TestCase
{
    #[Test]
    public function defaultStateIsEmptyAndNotStreaming(): void
    {
        $slice = new ConversationSlice();

        self::assertSame([], $slice->messages);
        self::assertFalse($slice->isStreaming);
        self::assertSame('', $slice->thinkingBuffer);
    }

    #[Test]
    public function addUserMessageAddsCompleteUserMessage(): void
    {
        $slice = new ConversationSlice()->addUserMessage('Hello, Leonidas.');

        self::assertCount(1, $slice->messages);
        self::assertSame('user', $slice->messages[0]->role);
        self::assertSame('Hello, Leonidas.', $slice->messages[0]->text);
        self::assertNull($slice->messages[0]->channel);
        self::assertTrue($slice->messages[0]->complete);
    }

    #[Test]
    public function appendTokenOnEmptyCreatesNewAssistantMessage(): void
    {
        $slice = new ConversationSlice()->appendToken('The phalanx holds.');

        self::assertCount(1, $slice->messages);
        self::assertSame('assistant', $slice->messages[0]->role);
        self::assertSame('The phalanx holds.', $slice->messages[0]->text);
        self::assertSame('message', $slice->messages[0]->channel);
        self::assertFalse($slice->messages[0]->complete);
        self::assertTrue($slice->isStreaming);
    }

    #[Test]
    public function appendTokenOnSameChannelAppendsToIncompleteMessage(): void
    {
        $slice = new ConversationSlice()
            ->appendToken('Sparta ')
            ->appendToken('stands.');

        self::assertCount(1, $slice->messages);
        self::assertSame('Sparta stands.', $slice->messages[0]->text);
        self::assertFalse($slice->messages[0]->complete);
    }

    #[Test]
    public function appendTokenOnDifferentChannelCreatesNewMessage(): void
    {
        $slice = new ConversationSlice()
            ->appendToken('deliberating...', 'thinking')
            ->appendToken('The answer is: hold.', 'message');

        self::assertCount(2, $slice->messages);
        self::assertSame('thinking', $slice->messages[0]->channel);
        self::assertSame('message', $slice->messages[1]->channel);
    }

    #[Test]
    public function finalizeMessageMarksLastMessageCompleteAndClearsStreaming(): void
    {
        $slice = new ConversationSlice()
            ->appendToken('Thermopylae.')
            ->finalizeMessage();

        self::assertCount(1, $slice->messages);
        self::assertTrue($slice->messages[0]->complete);
        self::assertFalse($slice->isStreaming);
        self::assertSame('', $slice->thinkingBuffer);
    }

    #[Test]
    public function appendTokenOnThinkingChannelPopulatesThinkingBuffer(): void
    {
        $slice = new ConversationSlice()
            ->appendToken('considering ', 'thinking')
            ->appendToken('options', 'thinking');

        self::assertSame('considering options', $slice->thinkingBuffer);
    }

    #[Test]
    public function sliceIsCopyOnModify(): void
    {
        $original = new ConversationSlice();
        $modified = $original->addUserMessage('test');

        self::assertSame([], $original->messages);
        self::assertCount(1, $modified->messages);
        self::assertNotSame($original, $modified);
    }
}
