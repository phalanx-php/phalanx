<?php

declare(strict_types=1);

namespace AgentBridge\Tab;

use AgentBridge\Agent\ClassifierAgent;
use AgentBridge\Agent\GeneratorAgent;
use AgentBridge\Agent\RepairAgent;
use AgentBridge\BridgeCommand;
use AgentBridge\BridgeConfig;
use AgentBridge\BridgeMessage;
use AgentBridge\ExtensionSession;
use AgentBridge\Lego\LegoDefinition;
use AgentBridge\Lego\LegoExecutor;
use AgentBridge\Lego\LegoLibrary;
use AgentBridge\Policy\PolicyRule;
use AgentBridge\Policy\PolicyStore;
use Phalanx\Athena\AgentResult;
use Phalanx\Concurrency\CancellationToken;
use Phalanx\ExecutionScope;
use Phalanx\Styx\Channel;
use Phalanx\Stream\Contract\StreamContext;
use Phalanx\Styx\Emitter;
use Phalanx\Styx\ScopedStream;
use Phalanx\Task\Task;
use React\Promise\Deferred;

use function React\Promise\race;

final class TabScope
{
    private(set) Channel $inbound;

    /**
     * Keyed by actionId or requestId.
     *
     * @var array<string, Deferred>
     */
    public array $pendingActions = [];

    private int $nextActionId = 1;

    public function __construct(
        private(set) int $tabId,
        private(set) string $url,
        private(set) string $title,
        private(set) ?string $domain,
        private(set) ExtensionSession $session,
        private(set) ExecutionScope $scope,
        private(set) CancellationToken $cancellation,
        private(set) LegoLibrary $legoLibrary,
        private(set) PolicyStore $policyStore,
        private(set) BridgeConfig $config,
    ) {
        $this->inbound = new Channel(bufferSize: 64);

        $session = $this->session;
        $tabId = $this->tabId;
        $config = $this->config;

        $this->inbound->withPressure(
            static function (bool $paused) use ($session, $tabId, $config): void {
                if ($paused) {
                    $session->send(BridgeCommand::throttle($tabId, maxEventsPerSec: $config->maxEventsPerSecThrottled));
                } else {
                    $session->send(BridgeCommand::resume($tabId));
                }
            }
        );
    }

    /**
     * Execute action steps on this tab and await the result.
     *
     * Races the extension's reply against $config->actionTimeoutSeconds. On timeout,
     * sends action.cancel to the extension so it can abort in-progress DOM manipulation,
     * then throws a RuntimeException. The pending deferred is always cleaned up.
     *
     * @param list<array<string, mixed>> $steps
     * @return array<string, mixed>
     */
    public function executeAction(array $steps): array
    {
        $actionId = 'act_' . $this->nextActionId++;
        $deferred = new Deferred();
        $this->pendingActions[$actionId] = $deferred;

        $this->session->send(BridgeCommand::executeAction($this->tabId, $actionId, $steps));

        $timeoutToken = CancellationToken::timeout($this->config->actionTimeoutSeconds);

        try {
            return $this->scope->await(self::raceWithTimeout($deferred, $timeoutToken));
        } catch (\Phalanx\Exception\CancelledException $e) {
            // Distinguish scope cancellation (tab disconnect) from action timeout.
            // The timeout token fires CancelledException; so does scope cancellation.
            // If the scope itself is cancelled this rethrow is correct -- the caller
            // propagates up to dispose(). If it is specifically the timeout token,
            // we replace with a descriptive RuntimeException and notify the extension.
            if (!$this->cancellation->isCancelled) {
                $this->session->send(BridgeCommand::cancelAction($this->tabId, $actionId));
                throw new \RuntimeException(
                    "Action {$actionId} timed out after {$this->config->actionTimeoutSeconds}s",
                    previous: $e,
                );
            }
            throw $e;
        } finally {
            $timeoutToken->cancel();
            unset($this->pendingActions[$actionId]);
        }
    }

    /**
     * Request structured DOM data from the content script.
     *
     * Races the extension's reply against $config->actionTimeoutSeconds. On timeout,
     * throws a RuntimeException. No cancel command is sent for DOM queries because
     * the extension drops unanswered requests after the tab navigates anyway.
     *
     * @param list<string>|null $attrs
     * @return list<array<string, string>>
     */
    public function queryDom(string $selector, ?array $attrs = null, ?int $limit = null): array
    {
        $requestId = 'dreq_' . $this->nextActionId++;
        $deferred = new Deferred();
        $this->pendingActions[$requestId] = $deferred;

        $this->session->send(BridgeCommand::requestDom($this->tabId, $requestId, $selector, $attrs, $limit));

        $timeoutToken = CancellationToken::timeout($this->config->actionTimeoutSeconds);

        try {
            return $this->scope->await(self::raceWithTimeout($deferred, $timeoutToken));
        } catch (\Phalanx\Exception\CancelledException $e) {
            if (!$this->cancellation->isCancelled) {
                throw new \RuntimeException(
                    "DOM query {$requestId} timed out after {$this->config->actionTimeoutSeconds}s",
                    previous: $e,
                );
            }
            throw $e;
        } finally {
            $timeoutToken->cancel();
            unset($this->pendingActions[$requestId]);
        }
    }

    /**
     * Builds a promise that races $deferred against a CancellationToken timer.
     *
     * When $timeoutToken fires it rejects with CancelledException, which propagates
     * through $scope->await() just like scope cancellation. The caller distinguishes
     * the two cases by checking $this->cancellation->isCancelled.
     *
     * The token itself (and its underlying Loop timer) is cancelled in the caller's
     * finally block to prevent the timer from outliving the action.
     */
    private static function raceWithTimeout(Deferred $deferred, CancellationToken $timeoutToken): \React\Promise\PromiseInterface
    {
        $timeoutPromise = new \React\Promise\Promise(
            static function ($_, $reject) use ($timeoutToken): void {
                $timeoutToken->onCancel(static function () use ($reject): void {
                    $reject(new \Phalanx\Exception\CancelledException('Action timeout'));
                });
            },
        );

        return race([$deferred->promise(), $timeoutPromise]);
    }

    public function handleNavigation(BridgeMessage $msg): void
    {
        $this->url = $msg->url ?? $this->url;
        $this->title = $msg->title ?? $this->title;
    }

    public function handleUserAction(BridgeMessage $msg): void
    {
        $this->policyStore->recordUserAction($this->domain ?? 'unknown', $msg->payload);
        $this->inbound->emit($msg);
    }

    public function handleActionResult(BridgeMessage $msg): void
    {
        $id = $msg->payload['actionId'] ?? null;
        if ($id === null) {
            return;
        }

        $deferred = $this->pendingActions[$id] ?? null;
        if ($deferred === null) {
            return;
        }

        if ($msg->payload['success'] ?? false) {
            $deferred->resolve($msg->payload['data'] ?? []);
        } else {
            $deferred->reject(new \RuntimeException($msg->payload['error'] ?? 'Action failed'));
        }
    }

    public function handleFlowControl(BridgeMessage $msg): void
    {
        // Extension reporting buffer pressure. Phase 2 stub.
        // Channel backpressure handles daemon-side congestion -- see Section 3.7.
    }

    /**
     * Wires the inbound Channel through stream operators and launches the pipeline
     * in a detached child fiber. Returns immediately; the pipeline runs concurrently.
     *
     * Stages: filter (classifiable types) -> throttle (0.5s) -> bufferWindow -> classifyBatch
     *
     * The pipeline terminates naturally when dispose() completes the inbound Channel,
     * which ends the producer generator and drains through all downstream operators.
     */
    public function startPipeline(): void
    {
        $inbound = $this->inbound;
        $config = $this->config;
        $tabScope = $this;

        $pipeline = ScopedStream::from(
            $this->scope,
            Emitter::produce(static function (Channel $ch, StreamContext $ctx) use ($inbound): void {
                foreach ($inbound->consume() as $msg) {
                    $ctx->throwIfCancelled();
                    $ch->emit($msg);
                }
            }),
        )
        ->filter(static function (mixed $msg): bool {
            assert($msg instanceof BridgeMessage);
            return match ($msg->type) {
                'dom.snapshot', 'dom.mutations', 'net.response' => true,
                default => false,
            };
        })
        ->throttle(0.5)
        ->bufferWindow($config->classifierBufferCount, $config->classifierBufferSeconds)
        ->onEach(static function (mixed $batch) use ($tabScope): void {
            assert(is_array($batch));
            self::classifyBatch($tabScope, $batch);
        });

        // Fire-and-forget: pipeline runs in a detached fiber for its full lifetime.
        $this->scope->defer(Task::of(
            static fn(ExecutionScope $s) => $pipeline->consume()
        ));
    }

    /**
     * Core pipeline sink. Awaits the AI classifier synchronously within the pipeline
     * fiber -- this is intentional backpressure (see daemon/SPEC.md Section 3.4).
     *
     * @param list<BridgeMessage> $batch
     */
    private static function classifyBatch(TabScope $tab, array $batch): void
    {
        $legoLibrary = $tab->scope->service(LegoLibrary::class);
        $policyStore = $tab->scope->service(PolicyStore::class);

        $legos = $legoLibrary->forDomain($tab->domain ?? 'unknown');

        if ($legos === []) {
            // No legos for this domain yet. GeneratorAgent is triggered by user.chat,
            // not automatically here -- classification requires something to classify against.
            return;
        }

        $domElements = self::extractDomElements($batch);

        if ($domElements === []) {
            return;
        }

        $policy = $policyStore->forDomain($tab->domain ?? 'unknown');
        $policyRules = $policy->rules !== []
            ? array_map(static fn(PolicyRule $r) => $r->toArray(), $policy->rules)
            : null;

        $classifier = new ClassifierAgent(
            availableLegos: $legos,
            domElements: $domElements,
            policyRules: $policyRules,
        );

        $result = $tab->scope->execute($classifier);

        if ($result instanceof AgentResult && $result->text !== '') {
            $classificationData = json_decode($result->text, true, 512, JSON_THROW_ON_ERROR);
            self::executeClassifications($tab, $classificationData, $legos, $legoLibrary);
        }
    }

    /** @param list<BridgeMessage> $batch */
    private static function extractDomElements(array $batch): array
    {
        $elements = [];

        foreach ($batch as $msg) {
            match ($msg->type) {
                'dom.snapshot' => $elements[] = [
                    'type' => 'snapshot',
                    'html' => $msg->payload['html'] ?? '',
                    'selector' => $msg->payload['selector'] ?? '',
                ],
                'dom.mutations' => $elements = array_merge($elements, array_map(
                    static fn(array $m) => ['type' => 'mutation', ...$m],
                    $msg->payload['mutations'] ?? [],
                )),
                'net.response' => $elements[] = [
                    'type' => 'network',
                    'url' => $msg->payload['url'] ?? $msg->url ?? '',
                    'status' => $msg->payload['status'] ?? 0,
                    'contentType' => $msg->payload['contentType'] ?? '',
                ],
                default => null,
            };
        }

        return $elements;
    }

    /**
     * @param list<LegoDefinition> $legos
     */
    private static function executeClassifications(
        TabScope $tab,
        mixed $classificationData,
        array $legos,
        LegoLibrary $library,
    ): void {
        if (!is_array($classificationData)) {
            return;
        }

        $legoMap = [];
        foreach ($legos as $lego) {
            $legoMap[$lego->name] = $lego;
        }

        $executor = $tab->executor();

        foreach ($classificationData as $classification) {
            $name = $classification['legoName'] ?? null;
            if ($name === null || !isset($legoMap[$name])) {
                continue;
            }

            $lego = $legoMap[$name];

            if (($classification['confidence'] ?? 0.0) < 0.7) {
                continue;
            }

            $succeeded = $executor->execute($lego, $library);

            if (!$succeeded && $lego->failures >= 3) {
                self::requestRepair($tab, $lego);
            }

            $tab->session->send(BridgeCommand::uiUpdate('confidence', [
                'tabId' => $tab->tabId,
                'action' => $lego->name,
                'confidence' => $lego->confidence,
                'executions' => $lego->executions + 1,
                'overrides' => $lego->overrides,
            ]));
        }
    }

    private static function requestRepair(TabScope $tab, LegoDefinition $lego): void
    {
        try {
            $domElements = $tab->queryDom('body', limit: 500);
            $currentDom = json_encode($domElements, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return;
        }

        $repairAgent = new RepairAgent(
            brokenLego: $lego,
            currentDom: $currentDom,
        );

        try {
            $result = $tab->scope->execute($repairAgent);

            if ($result instanceof AgentResult && $result->text !== '') {
                $repairedSteps = json_decode($result->text, true, 512, JSON_THROW_ON_ERROR);
                $repairedLego = $lego->withRepairedSteps($repairedSteps);
                $tab->scope->service(LegoLibrary::class)->save($repairedLego);
                $tab->say("Repaired '{$lego->name}' -- updated selectors to match current page structure.");
            }
        } catch (\Throwable $e) {
            $tab->say("Failed to repair '{$lego->name}': {$e->getMessage()}");
        }
    }

    public function handleUserChat(BridgeMessage $msg): void
    {
        $text = $msg->payload['text'] ?? '';
        if ($text === '') {
            return;
        }

        $this->say("Understood. Analyzing the page for: {$text}");

        // Capture the tab reference explicitly -- the static closure cannot reference $this.
        $tab = $this;

        $this->scope->defer(Task::of(
            static function (ExecutionScope $s) use ($text, $tab): void {
                try {
                    $domElements = $tab->queryDom('body', limit: 500);
                    $currentDom = json_encode($domElements, JSON_THROW_ON_ERROR);
                } catch (\Throwable) {
                    $tab->say('Could not read the page. Is the tab still connected?');
                    return;
                }

                $generator = new GeneratorAgent(
                    domSnapshot: $currentDom,
                    userIntent: $text,
                    domain: $tab->domain,
                );

                // ValidateSelector accesses the tab via 'tabScope' scope attribute.
                $generatorScope = $s->withAttribute('tabScope', $tab);
                $result = $generatorScope->execute($generator);

                if ($result instanceof AgentResult && $result->text !== '') {
                    $legos = json_decode($result->text, true, 512, JSON_THROW_ON_ERROR);
                    $library = $s->service(LegoLibrary::class);

                    foreach ($legos as $legoData) {
                        $lego = LegoDefinition::fromArray([
                            ...$legoData,
                            'domain' => $tab->domain,
                        ]);
                        $library->save($lego);
                    }

                    $tab->say('Created ' . count($legos) . ' new action(s) for ' . ($tab->domain ?? 'this site') . '.');
                } else {
                    $tab->say('I could not generate actions for that intent. Try being more specific.');
                }
            }
        ));
    }

    public function executor(): LegoExecutor
    {
        return new LegoExecutor($this);
    }

    public function say(string $text): void
    {
        $this->session->send(BridgeCommand::uiUpdate('conversation', [
            'tabId' => $this->tabId,
            'role' => 'agent',
            'text' => $text,
        ]));
    }

    public function dispose(): void
    {
        foreach ($this->pendingActions as $deferred) {
            $deferred->reject(new \RuntimeException('Tab disconnected'));
        }

        $this->pendingActions = [];
        $this->inbound->complete();
        $this->cancellation->cancel();
        $this->scope->dispose();
    }
}
