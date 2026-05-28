<?php

declare(strict_types=1);

namespace AgentBridge\Tests\Unit;

use AgentBridge\BridgeCommand;
use PHPUnit\Framework\TestCase;

final class BridgeCommandTest extends TestCase
{
    public function testExecuteActionSerializesCorrectly(): void
    {
        $cmd = BridgeCommand::executeAction(42, 'act_1', [
            ['op' => 'click', 'selector' => '.btn'],
        ]);

        $json = json_decode($cmd->toJson(), true);

        self::assertSame('action.execute', $json['type']);
        self::assertSame(42, $json['tabId']);
        self::assertSame('act_1', $json['actionId']);
        self::assertSame([['op' => 'click', 'selector' => '.btn']], $json['steps']);
    }

    public function testCancelActionSerializesCorrectly(): void
    {
        $cmd = BridgeCommand::cancelAction(7, 'act_3');

        $json = json_decode($cmd->toJson(), true);

        self::assertSame('action.cancel', $json['type']);
        self::assertSame(7, $json['tabId']);
        self::assertSame('act_3', $json['actionId']);
    }

    public function testRequestDomWithAllParams(): void
    {
        $cmd = BridgeCommand::requestDom(3, 'dreq_1', '.email-row', ['data-id', 'data-sender'], 50);

        $json = json_decode($cmd->toJson(), true);

        self::assertSame('dom.request', $json['type']);
        self::assertSame(3, $json['tabId']);
        self::assertSame('dreq_1', $json['requestId']);
        self::assertSame('.email-row', $json['selector']);
        self::assertSame(['data-id', 'data-sender'], $json['attrs']);
        self::assertSame(50, $json['limit']);
    }

    public function testRequestDomOmitsNullOptionals(): void
    {
        $cmd = BridgeCommand::requestDom(1, 'dreq_2', 'body');

        $json = json_decode($cmd->toJson(), true);

        self::assertArrayNotHasKey('attrs', $json);
        self::assertArrayNotHasKey('limit', $json);
        self::assertArrayNotHasKey('actionId', $json);
    }

    public function testUiUpdateSerializesCorrectly(): void
    {
        $cmd = BridgeCommand::uiUpdate('status', ['tabId' => 1, 'state' => 'connected']);

        $json = json_decode($cmd->toJson(), true);

        self::assertSame('ui.update', $json['type']);
        self::assertSame('status', $json['target']);
        self::assertSame(['tabId' => 1, 'state' => 'connected'], $json['data']);
        self::assertArrayNotHasKey('tabId', $json);
    }

    public function testThrottleSerializesCorrectly(): void
    {
        $cmd = BridgeCommand::throttle(5, 10);

        $json = json_decode($cmd->toJson(), true);

        self::assertSame('flow.throttle', $json['type']);
        self::assertSame(5, $json['tabId']);
        self::assertSame(10, $json['maxEventsPerSec']);
    }

    public function testResumeSerializesCorrectly(): void
    {
        $cmd = BridgeCommand::resume(8);

        $json = json_decode($cmd->toJson(), true);

        self::assertSame('flow.resume', $json['type']);
        self::assertSame(8, $json['tabId']);
    }


}
