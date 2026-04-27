<?php

declare(strict_types=1);

namespace Phalanx\Athena\Tests\Integration;

use Phalanx\Athena\Message\Content;
use Phalanx\Athena\Message\ContentKind;
use Phalanx\Athena\Message\Conversation;
use Phalanx\Athena\Message\Message;
use Phalanx\Athena\Message\Role;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ConversationTest extends TestCase
{
    #[Test]
    public function creates_empty_conversation(): void
    {
        $conv = Conversation::create();

        $this->assertNull($conv->systemPrompt);
        $this->assertSame(0, $conv->count());
    }

    #[Test]
    public function builds_conversation_with_system_prompt(): void
    {
        $conv = Conversation::create()
            ->system('You are a helpful assistant.');

        $this->assertSame('You are a helpful assistant.', $conv->systemPrompt);
    }

    #[Test]
    public function appends_messages_immutably(): void
    {
        $conv1 = Conversation::create()->system('System prompt');
        $conv2 = $conv1->user('Hello');
        $conv3 = $conv2->assistant('Hi there');

        $this->assertSame(0, $conv1->count());
        $this->assertSame(1, $conv2->count());
        $this->assertSame(2, $conv3->count());
    }

    #[Test]
    public function serializes_to_array_and_back(): void
    {
        $conv = Conversation::create()
            ->system('System')
            ->user('Hello')
            ->assistant('Hi');

        $array = $conv->toArray();
        $restored = Conversation::fromArray($array);

        $this->assertSame(2, $restored->count());

        $restoredArray = $restored->toArray();
        $this->assertSame($array, $restoredArray);
    }

    #[Test]
    public function appends_tool_results(): void
    {
        $conv = Conversation::create()
            ->user('What is 2+2?')
            ->assistant('Let me calculate that.')
            ->appendToolResult('call_123', ['result' => 4]);

        $this->assertSame(3, $conv->count());
    }

    #[Test]
    public function message_text_property_concatenates_text_blocks(): void
    {
        $msg = Message::user([
            Content::text('Hello '),
            Content::text('World'),
        ]);

        $this->assertSame('Hello World', $msg->text);
    }

    #[Test]
    public function message_roles(): void
    {
        $this->assertSame(Role::System, Message::system('test')->role);
        $this->assertSame(Role::User, Message::user('test')->role);
        $this->assertSame(Role::Assistant, Message::assistant('test')->role);
    }

    #[Test]
    public function content_types(): void
    {
        $text = Content::text('hello');
        $this->assertSame(ContentKind::Text, $text->kind);
        $this->assertSame('hello', $text->text);

        $image = Content::image('base64data', 'image/jpeg');
        $this->assertSame(ContentKind::Image, $image->kind);
        $this->assertSame('image/jpeg', $image->mediaType);

        $toolCall = Content::toolCall('id', 'name', ['arg' => 'val']);
        $this->assertSame(ContentKind::ToolCall, $toolCall->kind);
        $this->assertSame('id', $toolCall->toolCallId);

        $toolResult = Content::toolResult('id', 'result');
        $this->assertSame(ContentKind::ToolResult, $toolResult->kind);
    }

    #[Test]
    public function message_to_array_simple_text(): void
    {
        $msg = Message::user('hello');
        $arr = $msg->toArray();

        $this->assertSame('user', $arr['role']);
        $this->assertSame('hello', $arr['content']);
    }

    #[Test]
    public function message_to_array_multi_content(): void
    {
        $msg = Message::user([
            Content::text('What is this?'),
            Content::image('base64', 'image/png'),
        ]);

        $arr = $msg->toArray();

        $this->assertSame('user', $arr['role']);
        $this->assertIsArray($arr['content']);
        $this->assertCount(2, $arr['content']);
    }

    #[Test]
    public function from_array_restores_system_prompt(): void
    {
        $data = [
            ['role' => 'system', 'content' => 'Be helpful'],
            ['role' => 'user', 'content' => 'Hi'],
        ];

        $conv = Conversation::fromArray($data);

        $this->assertSame('Be helpful', $conv->systemPrompt);
        $this->assertSame(1, $conv->count());
    }
}
