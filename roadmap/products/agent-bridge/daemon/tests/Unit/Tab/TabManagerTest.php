<?php

declare(strict_types=1);

namespace AgentBridge\Tests\Unit\Tab;

use AgentBridge\BridgeConfig;
use AgentBridge\BridgeMessage;
use AgentBridge\ExtensionSession;
use AgentBridge\Lego\LegoLibrary;
use AgentBridge\Policy\PolicyStore;
use AgentBridge\Tab\TabManager;
use AgentBridge\Tab\TabScope;
use Phalanx\AppHost;
use Phalanx\Concurrency\CancellationToken;
use Phalanx\ExecutionScope;
use Phalanx\Testing\Probe\LeakSensor;
use Phalanx\Hermes\WsConnection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TabManagerTest extends TestCase
{
    private TabManager $manager;
    private ExtensionSession $session;

    protected function setUp(): void
    {
        $this->manager = new TabManager(
            legoLibrary: new LegoLibrary('/tmp/test-legos'),
            policyStore: new PolicyStore('/tmp/test-policies'),
            config: new BridgeConfig(dataDir: '/tmp/test-bridge'),
        );

        $conn = new WsConnection('test-conn-1');
        $this->session = new ExtensionSession($conn);
    }

    private function makeAppHost(): AppHost
    {
        $scope = $this->createMock(ExecutionScope::class);
        $scope->method('service')->willReturn(new \stdClass());

        $app = $this->createMock(AppHost::class);
        $app->method('createScope')->willReturn($scope);

        return $app;
    }

    /** Returns an AppHost whose underlying scope mock expects dispose() to be called exactly $times. */
    private function makeAppHostExpectingDispose(int $times): AppHost
    {
        $scope = $this->createMock(ExecutionScope::class);
        $scope->method('service')->willReturn(new \stdClass());
        $scope->expects($this->exactly($times))->method('dispose');

        $app = $this->createMock(AppHost::class);
        $app->method('createScope')->willReturn($scope);

        return $app;
    }

    private function tabConnectMsg(int $tabId, string $url = 'https://example.com', string $domain = 'example.com'): BridgeMessage
    {
        return BridgeMessage::fromJson([
            'type'   => 'tab.connect',
            'tabId'  => $tabId,
            'url'    => $url,
            'domain' => $domain,
            'title'  => 'Example',
        ]);
    }

    #[Test]
    public function connect_tab_adds_to_connected_tabs(): void
    {
        $this->manager->setApp($this->makeAppHost());
        $this->manager->registerSession($this->session);

        $this->manager->handleTabMessage($this->tabConnectMsg(42), $this->session);

        $tabs = $this->manager->connectedTabs();
        self::assertCount(1, $tabs);
        self::assertSame(42, $tabs[0]->tabId);
    }

    #[Test]
    public function duplicate_tab_connect_is_idempotent(): void
    {
        $this->manager->setApp($this->makeAppHost());
        $this->manager->registerSession($this->session);

        $this->manager->handleTabMessage($this->tabConnectMsg(10), $this->session);
        $this->manager->handleTabMessage($this->tabConnectMsg(10), $this->session);

        self::assertCount(1, $this->manager->connectedTabs());
    }

    #[Test]
    public function disconnect_tab_removes_it(): void
    {
        $this->manager->setApp($this->makeAppHostExpectingDispose(1));
        $this->manager->registerSession($this->session);

        $this->manager->handleTabMessage($this->tabConnectMsg(7), $this->session);
        self::assertCount(1, $this->manager->connectedTabs());

        $this->manager->handleTabMessage(
            BridgeMessage::fromJson(['type' => 'tab.disconnect', 'tabId' => 7]),
            $this->session,
        );

        self::assertCount(0, $this->manager->connectedTabs());
    }

    #[Test]
    public function disconnect_unknown_tab_is_no_op(): void
    {
        $this->manager->handleTabMessage(
            BridgeMessage::fromJson(['type' => 'tab.disconnect', 'tabId' => 999]),
            $this->session,
        );

        self::assertCount(0, $this->manager->connectedTabs());
    }

    #[Test]
    public function unregister_session_disposes_all_session_tabs(): void
    {
        $this->manager->setApp($this->makeAppHostExpectingDispose(2));
        $this->manager->registerSession($this->session);

        $this->manager->handleTabMessage($this->tabConnectMsg(1), $this->session);
        $this->manager->handleTabMessage($this->tabConnectMsg(2, 'https://other.com', 'other.com'), $this->session);
        self::assertCount(2, $this->manager->connectedTabs());

        $this->manager->unregisterSession($this->session);

        self::assertCount(0, $this->manager->connectedTabs());
    }

    #[Test]
    public function tab_navigate_updates_url_and_title(): void
    {
        $this->manager->setApp($this->makeAppHost());
        $this->manager->registerSession($this->session);

        $this->manager->handleTabMessage($this->tabConnectMsg(3), $this->session);
        $tab = $this->manager->tab(3);
        self::assertNotNull($tab);
        self::assertSame('https://example.com', $tab->url);

        $this->manager->handleTabMessage(
            BridgeMessage::fromJson([
                'type'  => 'tab.navigate',
                'tabId' => 3,
                'url'   => 'https://example.com/page2',
                'title' => 'Page 2',
            ]),
            $this->session,
        );

        self::assertSame('https://example.com/page2', $tab->url);
        self::assertSame('Page 2', $tab->title);
    }

    #[Test]
    public function handle_dom_message_emits_to_inbound_channel(): void
    {
        $this->manager->setApp($this->makeAppHost());
        $this->manager->registerSession($this->session);

        $this->manager->handleTabMessage($this->tabConnectMsg(5), $this->session);
        $tab = $this->manager->tab(5);
        self::assertNotNull($tab);

        $domMsg = BridgeMessage::fromJson([
            'type'      => 'dom.mutations',
            'tabId'     => 5,
            'mutations' => [['type' => 'childList', 'added' => 1]],
        ]);

        $this->manager->handleDomMessage($domMsg, $this->session);

        // Verify the message actually landed in the channel buffer.
        $buffer = (new \ReflectionProperty($tab->inbound, 'buffer'))->getValue($tab->inbound);
        self::assertCount(1, $buffer);
        self::assertSame($domMsg, $buffer[0]);
    }

    #[Test]
    public function handle_dom_response_resolves_pending_deferred(): void
    {
        $this->manager->setApp($this->makeAppHost());
        $this->manager->registerSession($this->session);

        $this->manager->handleTabMessage($this->tabConnectMsg(6), $this->session);
        $tab = $this->manager->tab(6);
        self::assertNotNull($tab);

        $deferred = new \React\Promise\Deferred();
        $tab->pendingActions['dreq_1'] = $deferred;

        $resolved = null;
        $deferred->promise()->then(static function (mixed $v) use (&$resolved): void {
            $resolved = $v;
        });

        $this->manager->handleDomResponse(
            BridgeMessage::fromJson([
                'type'      => 'dom.response',
                'tabId'     => 6,
                'requestId' => 'dreq_1',
                'elements'  => [['data-id' => 'msg_1']],
            ]),
            $this->session,
        );

        self::assertSame([['data-id' => 'msg_1']], $resolved);
    }

    #[Test]
    public function tab_is_garbage_collected_after_disconnect(): void
    {
        $this->manager->setApp($this->makeAppHost());
        $this->manager->registerSession($this->session);

        $this->manager->handleTabMessage($this->tabConnectMsg(9), $this->session);
        $tab = $this->manager->tab(9);
        self::assertNotNull($tab);

        $sensor = new LeakSensor();
        $sensor->watch($tab, TabScope::class);

        $this->manager->handleTabMessage(
            BridgeMessage::fromJson(['type' => 'tab.disconnect', 'tabId' => 9]),
            $this->session,
        );

        // Release the local reference so the only remaining holder would be TabManager.
        // TabManager removes the entry on disconnect, so no strong refs should survive.
        unset($tab);

        $sensor->assertAllCollected();
    }
}
