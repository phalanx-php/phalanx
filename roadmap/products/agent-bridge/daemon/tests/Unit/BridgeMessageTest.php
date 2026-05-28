<?php

declare(strict_types=1);

namespace AgentBridge\Tests\Unit;

use AgentBridge\BridgeMessage;
use PHPUnit\Framework\TestCase;

final class BridgeMessageTest extends TestCase
{
    public function testFromJsonTabConnect(): void
    {
        $msg = BridgeMessage::fromJson([
            'type' => 'tab.connect',
            'tabId' => 42,
            'url' => 'https://example.com/inbox',
            'title' => 'Inbox',
            'timestamp' => 1712345678.123,
        ]);

        self::assertSame('tab.connect', $msg->type);
        self::assertSame(42, $msg->tabId);
        self::assertSame('https://example.com/inbox', $msg->url);
        self::assertSame('Inbox', $msg->title);
        self::assertSame(1712345678.123, $msg->timestamp);
        self::assertSame([], $msg->payload);
    }

    public function testFromJsonDomSnapshot(): void
    {
        $msg = BridgeMessage::fromJson([
            'type' => 'dom.snapshot',
            'tabId' => 1,
            'url' => 'https://mail.google.com/',
            'elements' => [['tag' => 'div', 'id' => 'inbox']],
            'count' => 5,
        ]);

        self::assertSame('dom.snapshot', $msg->type);
        self::assertSame(['elements' => [['tag' => 'div', 'id' => 'inbox']], 'count' => 5], $msg->payload);
    }

    public function testFromJsonDomMutations(): void
    {
        $msg = BridgeMessage::fromJson([
            'type' => 'dom.mutations',
            'tabId' => 7,
            'mutations' => [['type' => 'childList', 'added' => 3]],
        ]);

        self::assertSame('dom.mutations', $msg->type);
        self::assertSame(7, $msg->tabId);
        self::assertSame([['type' => 'childList', 'added' => 3]], $msg->payload['mutations']);
    }

    public function testFromJsonDomResponse(): void
    {
        $msg = BridgeMessage::fromJson([
            'type' => 'dom.response',
            'tabId' => 3,
            'requestId' => 'dreq_1',
            'elements' => [['data-id' => 'msg_1']],
        ]);

        self::assertSame('dom.response', $msg->type);
        self::assertSame('dreq_1', $msg->payload['requestId']);
        self::assertSame([['data-id' => 'msg_1']], $msg->payload['elements']);
    }

    public function testFromJsonNetResponse(): void
    {
        $msg = BridgeMessage::fromJson([
            'type' => 'net.response',
            'tabId' => 5,
            'url' => 'https://api.example.com/data',
            'status' => 200,
            'body' => '{"ok":true}',
        ]);

        self::assertSame('net.response', $msg->type);
        self::assertSame(200, $msg->payload['status']);
    }

    public function testFromJsonUserAction(): void
    {
        $msg = BridgeMessage::fromJson([
            'type' => 'user.action',
            'tabId' => 2,
            'action' => 'click',
            'target' => '.submit-btn',
            'timestamp' => 1712345679000,
        ]);

        self::assertSame('user.action', $msg->type);
        self::assertSame('click', $msg->payload['action']);
        self::assertSame('.submit-btn', $msg->payload['target']);
    }

    public function testFromJsonActionResult(): void
    {
        $msg = BridgeMessage::fromJson([
            'type' => 'action.result',
            'tabId' => 4,
            'actionId' => 'act_1',
            'success' => true,
        ]);

        self::assertSame('action.result', $msg->type);
        self::assertSame('act_1', $msg->payload['actionId']);
        self::assertTrue($msg->payload['success']);
    }

    public function testFromJsonFlowPressure(): void
    {
        $msg = BridgeMessage::fromJson([
            'type' => 'flow.pressure',
            'tabId' => 10,
            'bufferDepth' => 47,
        ]);

        self::assertSame('flow.pressure', $msg->type);
        self::assertSame(47, $msg->payload['bufferDepth']);
    }

    public function testFromJsonUnknownTypePassesThrough(): void
    {
        $msg = BridgeMessage::fromJson([
            'type' => 'custom.extension.event',
            'tabId' => 99,
            'someData' => 'value',
        ]);

        self::assertSame('custom.extension.event', $msg->type);
        self::assertSame('value', $msg->payload['someData']);
    }

    public function testFromJsonMissingTypeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing message type');

        BridgeMessage::fromJson(['tabId' => 1, 'url' => 'https://example.com']);
    }

    public function testFromJsonNullableFieldsDefaultToNull(): void
    {
        $msg = BridgeMessage::fromJson(['type' => 'tab.disconnect']);

        self::assertNull($msg->tabId);
        self::assertNull($msg->url);
        self::assertNull($msg->title);
        self::assertNull($msg->timestamp);
    }

    public function testDomainFromExplicitWireField(): void
    {
        $msg = BridgeMessage::fromJson([
            'type' => 'tab.connect',
            'url' => 'https://mail.google.com/mail/u/0/#inbox',
            'domain' => 'mail.google.com',
        ]);

        self::assertSame('mail.google.com', $msg->domain);
        self::assertArrayNotHasKey('domain', $msg->payload);
    }

    public function testDomainDerivedFromUrlWhenNotExplicit(): void
    {
        $msg = BridgeMessage::fromJson([
            'type' => 'tab.connect',
            'url' => 'https://mail.google.com/mail/u/0/#inbox',
        ]);

        self::assertSame('mail.google.com', $msg->domain);
    }

    public function testDomainIsNullWhenNoUrlOrDomainField(): void
    {
        $msg = BridgeMessage::fromJson(['type' => 'tab.disconnect']);

        self::assertNull($msg->domain);
    }

    public function testDomainIsNullWhenUrlIsMalformed(): void
    {
        $msg = BridgeMessage::fromJson(['type' => 'tab.connect', 'url' => 'not-a-url']);

        self::assertNull($msg->domain);
    }

    public function testFromJsonTabDisconnect(): void
    {
        $msg = BridgeMessage::fromJson(['type' => 'tab.disconnect', 'tabId' => 1]);

        self::assertSame('tab.disconnect', $msg->type);
        self::assertSame(1, $msg->tabId);
        self::assertSame([], $msg->payload);
    }

    public function testFromJsonTabNavigate(): void
    {
        $msg = BridgeMessage::fromJson([
            'type' => 'tab.navigate',
            'tabId' => 1,
            'url' => 'https://example.com/page2',
            'title' => 'Page 2',
        ]);

        self::assertSame('tab.navigate', $msg->type);
        self::assertSame(1, $msg->tabId);
        self::assertSame('https://example.com/page2', $msg->url);
        self::assertSame('Page 2', $msg->title);
    }

    public function testFromJsonNetRequest(): void
    {
        $msg = BridgeMessage::fromJson([
            'type' => 'net.request',
            'tabId' => 1,
            'requestId' => 'req_1',
            'method' => 'GET',
            'url' => 'https://api.example.com/data',
            'timestamp' => 1700000000000,
        ]);

        self::assertSame('net.request', $msg->type);
        self::assertSame(1, $msg->tabId);
        self::assertSame('req_1', $msg->payload['requestId']);
        self::assertSame('GET', $msg->payload['method']);
    }

    public function testFromJsonUserChat(): void
    {
        $msg = BridgeMessage::fromJson([
            'type' => 'user.chat',
            'tabId' => 1,
            'text' => 'archive all newsletters',
        ]);

        self::assertSame('user.chat', $msg->type);
        self::assertSame(1, $msg->tabId);
        self::assertSame('archive all newsletters', $msg->payload['text']);
    }

    public function testKnownFieldsAreNotDuplicatedInPayload(): void
    {
        $msg = BridgeMessage::fromJson([
            'type' => 'dom.snapshot',
            'tabId' => 1,
            'url' => 'https://example.com',
            'title' => 'Example',
            'timestamp' => 1234567890.0,
            'extra' => 'data',
        ]);

        self::assertArrayNotHasKey('type', $msg->payload);
        self::assertArrayNotHasKey('tabId', $msg->payload);
        self::assertArrayNotHasKey('url', $msg->payload);
        self::assertArrayNotHasKey('title', $msg->payload);
        self::assertArrayNotHasKey('timestamp', $msg->payload);
        self::assertSame('data', $msg->payload['extra']);
    }
}
