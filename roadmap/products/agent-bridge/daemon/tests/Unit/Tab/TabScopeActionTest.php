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
use Phalanx\Testing\TestScope;
use Phalanx\Hermes\WsConnection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function React\Async\async;
use function React\Async\await;
use function React\Async\delay;

final class TabScopeActionTest extends TestCase
{
    private static function makeSession(): array
    {
        $conn    = new WsConnection('test-action-conn');
        $session = new ExtensionSession($conn);
        return [$session, $conn];
    }

    private static function makeTab(
        ExecutionScope $scope,
        ExtensionSession $session,
        float $actionTimeoutSeconds = 30.0,
    ): TabScope {
        return new TabScope(
            tabId:        1,
            url:          'https://example.com',
            title:        'Example',
            domain:       'example.com',
            session:      $session,
            scope:        $scope,
            cancellation: CancellationToken::create(),
            legoLibrary:  new LegoLibrary(sys_get_temp_dir() . '/ab-test-legos'),
            policyStore:  new PolicyStore(sys_get_temp_dir() . '/ab-test-policies'),
            config:       new BridgeConfig(
                dataDir:              sys_get_temp_dir() . '/ab-test-bridge',
                actionTimeoutSeconds: $actionTimeoutSeconds,
            ),
        );
    }

    // ---------------------------------------------------------------------------
    // executeAction round-trip
    // ---------------------------------------------------------------------------

    #[Test]
    public function execute_action_sends_command_and_awaits_result(): void
    {
        TestScope::run(static function (ExecutionScope $scope): void {
            [$session, $conn] = self::makeSession();
            $tab = self::makeTab($scope, $session);

            // Defer a fiber that resolves the pending action after one tick.
            // This simulates the extension responding asynchronously.
            $scope->defer(\Phalanx\Task\Task::of(
                static function (ExecutionScope $s) use ($tab): void {
                    await(async(static fn() => delay(0.01))());

                    $tab->handleActionResult(BridgeMessage::fromJson([
                        'type'     => 'action.result',
                        'tabId'    => 1,
                        'actionId' => 'act_1',
                        'success'  => true,
                        'data'     => ['clicked' => true],
                    ]));
                }
            ));

            $result = $tab->executeAction([['op' => 'click', 'selector' => '#submit']]);

            self::assertSame(['clicked' => true], $result);
        });
    }

    #[Test]
    public function execute_action_clears_pending_actions_after_resolution(): void
    {
        TestScope::run(static function (ExecutionScope $scope): void {
            [$session] = self::makeSession();
            $tab = self::makeTab($scope, $session);

            $scope->defer(\Phalanx\Task\Task::of(
                static function (ExecutionScope $s) use ($tab): void {
                    await(async(static fn() => delay(0.01))());

                    $tab->handleActionResult(BridgeMessage::fromJson([
                        'type'     => 'action.result',
                        'tabId'    => 1,
                        'actionId' => 'act_1',
                        'success'  => true,
                        'data'     => [],
                    ]));
                }
            ));

            $tab->executeAction([]);

            // The deferred must be removed once resolved so no accumulation occurs.
            self::assertSame([], $tab->pendingActions);
        });
    }

    // ---------------------------------------------------------------------------
    // executeAction timeout
    // ---------------------------------------------------------------------------

    #[Test]
    public function execute_action_times_out_and_sends_cancel(): void
    {
        TestScope::run(static function (ExecutionScope $scope): void {
            [$session, $conn] = self::makeSession();
            // 0.05s timeout: fast enough for tests, generous enough to not be flaky.
            $tab = self::makeTab($scope, $session, actionTimeoutSeconds: 0.05);

            $threw = false;

            try {
                // No deferred resolver is scheduled -- the action will never complete.
                $tab->executeAction([['op' => 'click', 'selector' => '#submit']]);
            } catch (\RuntimeException $e) {
                $threw = true;
                self::assertStringContainsString('timed out', $e->getMessage());
                self::assertStringContainsString('act_1', $e->getMessage());
            }

            self::assertTrue($threw, 'Expected RuntimeException on timeout');

            // Verify action.cancel was sent to the extension.
            $outbound = (new \ReflectionProperty($conn->outbound, 'buffer'))->getValue($conn->outbound);
            $types    = array_map(
                static function (mixed $frame): string {
                    $decoded = json_decode($frame->payload ?? '{}', true);
                    return $decoded['type'] ?? '';
                },
                $outbound,
            );

            self::assertContains('action.cancel', $types, 'Expected action.cancel to be sent on timeout');
        });
    }

    #[Test]
    public function execute_action_clears_pending_action_on_timeout(): void
    {
        TestScope::run(static function (ExecutionScope $scope): void {
            [$session] = self::makeSession();
            $tab = self::makeTab($scope, $session, actionTimeoutSeconds: 0.05);

            try {
                $tab->executeAction([]);
            } catch (\RuntimeException) {
                // Expected
            }

            self::assertSame([], $tab->pendingActions);
        });
    }

    // ---------------------------------------------------------------------------
    // queryDom round-trip
    // ---------------------------------------------------------------------------

    #[Test]
    public function query_dom_sends_request_and_awaits_response(): void
    {
        TestScope::run(static function (ExecutionScope $scope): void {
            [$session] = self::makeSession();
            $tab = self::makeTab($scope, $session);

            $scope->defer(\Phalanx\Task\Task::of(
                static function (ExecutionScope $s) use ($tab): void {
                    await(async(static fn() => delay(0.01))());

                    // TabManager::handleDomResponse() resolves the pending deferred directly.
                    // Simulate that path here by calling it on the tab's pendingActions.
                    $deferred = $tab->pendingActions['dreq_1'] ?? null;
                    if ($deferred !== null) {
                        $deferred->resolve([['tag' => 'BUTTON', 'id' => 'submit']]);
                    }
                }
            ));

            $elements = $tab->queryDom('#submit', ['id', 'tag']);

            self::assertCount(1, $elements);
            self::assertSame('BUTTON', $elements[0]['tag']);
        });
    }

    #[Test]
    public function query_dom_times_out_without_sending_cancel(): void
    {
        TestScope::run(static function (ExecutionScope $scope): void {
            [$session, $conn] = self::makeSession();
            $tab = self::makeTab($scope, $session, actionTimeoutSeconds: 0.05);

            $threw = false;

            try {
                $tab->queryDom('#submit');
            } catch (\RuntimeException $e) {
                $threw = true;
                self::assertStringContainsString('timed out', $e->getMessage());
            }

            self::assertTrue($threw, 'Expected RuntimeException on DOM query timeout');

            // DOM queries do not send action.cancel (no such concept for read-only requests).
            $outbound = (new \ReflectionProperty($conn->outbound, 'buffer'))->getValue($conn->outbound);
            $types    = array_map(
                static function (mixed $frame): string {
                    $decoded = json_decode($frame->payload ?? '{}', true);
                    return $decoded['type'] ?? '';
                },
                $outbound,
            );

            self::assertNotContains('action.cancel', $types, 'DOM query timeout must not send action.cancel');
        });
    }
}
