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
use Phalanx\Hermes\WsConnection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use React\Promise\Deferred;

final class TabScopeTest extends TestCase
{
    private ExtensionSession $session;

    protected function setUp(): void
    {
        $conn = new WsConnection('test-conn-scope');
        $this->session = new ExtensionSession($conn);
    }

    private function makeScope(): TabScope
    {
        $execScope = $this->createMock(ExecutionScope::class);

        $cancel = CancellationToken::create();

        return new TabScope(
            tabId: 1,
            url: 'https://example.com',
            title: 'Example',
            domain: 'example.com',
            session: $this->session,
            scope: $execScope,
            cancellation: $cancel,
            legoLibrary: new LegoLibrary('/tmp/test-legos'),
            policyStore: new PolicyStore('/tmp/test-policies'),
            config: new BridgeConfig(dataDir: '/tmp/test-bridge'),
        );
    }

    #[Test]
    public function dispose_calls_scope_dispose(): void
    {
        $execScope = $this->createMock(ExecutionScope::class);
        $execScope->expects($this->once())->method('dispose');

        $tab = new TabScope(
            tabId: 1,
            url: 'https://example.com',
            title: 'Example',
            domain: 'example.com',
            session: $this->session,
            scope: $execScope,
            cancellation: CancellationToken::create(),
            legoLibrary: new LegoLibrary('/tmp/test-legos'),
            policyStore: new PolicyStore('/tmp/test-policies'),
            config: new BridgeConfig(dataDir: '/tmp/test-bridge'),
        );

        $tab->dispose();
    }

    #[Test]
    public function dispose_cancels_the_cancellation_token(): void
    {
        $execScope = $this->createMock(ExecutionScope::class);
        $cancel = CancellationToken::create();

        $tab = new TabScope(
            tabId: 1,
            url: 'https://example.com',
            title: 'Example',
            domain: 'example.com',
            session: $this->session,
            scope: $execScope,
            cancellation: $cancel,
            legoLibrary: new LegoLibrary('/tmp/test-legos'),
            policyStore: new PolicyStore('/tmp/test-policies'),
            config: new BridgeConfig(dataDir: '/tmp/test-bridge'),
        );

        self::assertFalse($cancel->isCancelled);
        $tab->dispose();
        self::assertTrue($cancel->isCancelled);
    }

    #[Test]
    public function dispose_rejects_all_pending_deferreds(): void
    {
        $tab = $this->makeScope();

        $deferred1 = new Deferred();
        $deferred2 = new Deferred();
        $tab->pendingActions['act_1'] = $deferred1;
        $tab->pendingActions['act_2'] = $deferred2;

        $rejected = [];
        $deferred1->promise()->then(null, static function (\Throwable $e) use (&$rejected): void {
            $rejected[] = $e->getMessage();
        });
        $deferred2->promise()->then(null, static function (\Throwable $e) use (&$rejected): void {
            $rejected[] = $e->getMessage();
        });

        $tab->dispose();

        self::assertCount(2, $rejected);
        self::assertContains('Tab disconnected', $rejected);
    }

    #[Test]
    public function dispose_clears_pending_actions(): void
    {
        $tab = $this->makeScope();

        $deferred = new Deferred();
        $deferred->promise()->then(null, static fn(\Throwable $e) => null); // absorb rejection
        $tab->pendingActions['act_1'] = $deferred;

        $tab->dispose();

        self::assertSame([], $tab->pendingActions);
    }

    #[Test]
    public function dispose_completes_inbound_channel(): void
    {
        $tab = $this->makeScope();

        self::assertTrue($tab->inbound->isOpen);
        $tab->dispose();
        self::assertFalse($tab->inbound->isOpen);
    }

    #[Test]
    public function handle_navigation_updates_url_and_title(): void
    {
        $tab = $this->makeScope();

        self::assertSame('https://example.com', $tab->url);
        self::assertSame('Example', $tab->title);

        $tab->handleNavigation(BridgeMessage::fromJson([
            'type'  => 'tab.navigate',
            'tabId' => 1,
            'url'   => 'https://example.com/inbox',
            'title' => 'Inbox',
        ]));

        self::assertSame('https://example.com/inbox', $tab->url);
        self::assertSame('Inbox', $tab->title);
    }

    #[Test]
    public function handle_navigation_preserves_existing_values_when_fields_absent(): void
    {
        $tab = $this->makeScope();

        $tab->handleNavigation(BridgeMessage::fromJson([
            'type'  => 'tab.navigate',
            'tabId' => 1,
        ]));

        self::assertSame('https://example.com', $tab->url);
        self::assertSame('Example', $tab->title);
    }

    #[Test]
    public function handle_action_result_resolves_deferred_on_success(): void
    {
        $tab = $this->makeScope();

        $deferred = new Deferred();
        $tab->pendingActions['act_1'] = $deferred;

        $resolved = null;
        $deferred->promise()->then(static function (mixed $v) use (&$resolved): void {
            $resolved = $v;
        });

        $tab->handleActionResult(BridgeMessage::fromJson([
            'type'     => 'action.result',
            'tabId'    => 1,
            'actionId' => 'act_1',
            'success'  => true,
            'data'     => ['clicked' => true],
        ]));

        self::assertSame(['clicked' => true], $resolved);
    }

    #[Test]
    public function handle_action_result_rejects_deferred_on_failure(): void
    {
        $tab = $this->makeScope();

        $deferred = new Deferred();
        $tab->pendingActions['act_1'] = $deferred;

        $error = null;
        $deferred->promise()->then(null, static function (\Throwable $e) use (&$error): void {
            $error = $e;
        });

        $tab->handleActionResult(BridgeMessage::fromJson([
            'type'     => 'action.result',
            'tabId'    => 1,
            'actionId' => 'act_1',
            'success'  => false,
            'error'    => 'Element not found',
        ]));

        self::assertInstanceOf(\RuntimeException::class, $error);
        self::assertSame('Element not found', $error->getMessage());
    }

    #[Test]
    public function handle_action_result_ignores_unknown_action_id(): void
    {
        $tab = $this->makeScope();

        // No pending actions registered -- should be silent no-op
        $tab->handleActionResult(BridgeMessage::fromJson([
            'type'     => 'action.result',
            'tabId'    => 1,
            'actionId' => 'act_999',
            'success'  => true,
        ]));

        self::assertSame([], $tab->pendingActions);
    }

    #[Test]
    public function handle_action_result_ignores_missing_action_id(): void
    {
        $tab = $this->makeScope();

        // Payload without actionId -- should be silent no-op
        $tab->handleActionResult(BridgeMessage::fromJson([
            'type'    => 'action.result',
            'tabId'   => 1,
            'success' => true,
        ]));

        self::assertSame([], $tab->pendingActions);
    }
}
