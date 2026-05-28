<?php

declare(strict_types=1);

namespace AgentBridge\Tests\Unit;

use AgentBridge\BridgeConfig;
use AgentBridge\BridgeGateway;
use AgentBridge\BridgeMessage;
use AgentBridge\ExtensionSession;
use AgentBridge\Lego\LegoLibrary;
use AgentBridge\Policy\PolicyStore;
use AgentBridge\Tab\TabManager;
use Phalanx\AppHost;
use Phalanx\Concurrency\CancellationToken;
use Phalanx\ExecutionScope;
use Phalanx\Hermes\WsConnection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use React\Promise\Deferred;

final class BridgeGatewayTest extends TestCase
{
    private TabManager $tabManager;
    private ExtensionSession $session;

    protected function setUp(): void
    {
        $this->tabManager = new TabManager(
            legoLibrary: new LegoLibrary('/tmp/test-legos'),
            policyStore: new PolicyStore('/tmp/test-policies'),
            config: new BridgeConfig(dataDir: '/tmp/test-bridge'),
        );

        $conn = new WsConnection('test-conn-gateway');
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

    private function connectTab(int $tabId, string $url = 'https://example.com'): void
    {
        $this->tabManager->setApp($this->makeAppHost());
        $this->tabManager->registerSession($this->session);
        $this->tabManager->handleTabMessage(
            BridgeMessage::fromJson(['type' => 'tab.connect', 'tabId' => $tabId, 'url' => $url, 'domain' => parse_url($url, PHP_URL_HOST)]),
            $this->session,
        );
    }

    #[Test]
    public function dom_response_routes_to_pending_deferred_not_inbound_channel(): void
    {
        // Critical routing invariant: dom.response is a request-reply that must resolve
        // its pending Deferred by requestId. It must NOT be emitted into the stream pipeline
        // (handleDomMessage), which would cause the ClassifierAgent to process a spurious event.
        $this->connectTab(1);
        $tab = $this->tabManager->tab(1);
        self::assertNotNull($tab);

        $deferred = new Deferred();
        $tab->pendingActions['dreq_1'] = $deferred;

        $resolved = null;
        $deferred->promise()->then(static function (mixed $v) use (&$resolved): void {
            $resolved = $v;
        });

        BridgeGateway::routeMessage(
            BridgeMessage::fromJson(['type' => 'dom.response', 'tabId' => 1, 'requestId' => 'dreq_1', 'elements' => [['id' => 'row1']]]),
            $this->tabManager,
            $this->session,
        );

        // Deferred must be resolved -- dom.response reached handleDomResponse
        self::assertSame([['id' => 'row1']], $resolved);

        // Channel buffer must be empty -- dom.response was NOT routed to handleDomMessage
        $buffer = (new \ReflectionProperty($tab->inbound, 'buffer'))->getValue($tab->inbound);
        self::assertCount(0, $buffer, 'dom.response must not be emitted into the inbound channel');
    }

    #[Test]
    public function unknown_message_type_is_silently_ignored(): void
    {
        $this->connectTab(2);
        $tab = $this->tabManager->tab(2);
        self::assertNotNull($tab);

        // No exception, no channel emission, no state change
        BridgeGateway::routeMessage(
            BridgeMessage::fromJson(['type' => 'foo.bar', 'tabId' => 2]),
            $this->tabManager,
            $this->session,
        );

        $buffer = (new \ReflectionProperty($tab->inbound, 'buffer'))->getValue($tab->inbound);
        self::assertCount(0, $buffer);
        self::assertSame([], $tab->pendingActions);
    }
}
