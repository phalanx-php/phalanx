<?php

declare(strict_types=1);

namespace AegisSwoole\Tests\Scenarios;

use AegisSwoole\Agent\AgentTask;
use AegisSwoole\Application;
use AegisSwoole\Llm\LlmConfig;
use AegisSwoole\Scope\ExecutionScope;
use AegisSwoole\Service\ServiceBundle;
use AegisSwoole\Service\Services;
use AegisSwoole\Tests\Assertions;
use AegisSwoole\Tests\Harness;
use AegisSwoole\Tests\Result;
use AegisSwoole\Worker\DispatchStrategy;
use AegisSwoole\Worker\ParallelConfig;
use AegisSwoole\Worker\ParallelWorkerDispatch;
use AegisSwoole\Worker\WorkerSupervisor;
use OpenSwoole\Coroutine;

class AgentScenarios
{
    public function __construct(private readonly LlmConfig $config)
    {
    }

    private static function buildAppWithWorkers(int $agents): Application
    {
        $bundle = new class implements ServiceBundle {
            public function services(Services $services, array $context): void
            {
            }
        };
        $config = new ParallelConfig(
            agents: $agents,
            mailboxLimit: 16,
            strategy: DispatchStrategy::RoundRobin,
            workerScript: __DIR__ . '/../../bin/worker-runtime.php',
            autoloadPath: __DIR__ . '/../../vendor/autoload.php',
        );
        $supervisor = new WorkerSupervisor($config);
        $dispatch = new ParallelWorkerDispatch($supervisor);
        return Application::starting([])
            ->providers($bundle)
            ->withWorkerDispatch($dispatch)
            ->compile()
            ->startup();
    }

    public function register(Harness $h): void
    {
        // Prompts across this battery deliberately ask for one-token / one-word
        // replies and pin `maxTokens` low. The agent battery validates the
        // worker dispatch + parent-mediated coordination plumbing — not the
        // model's prose. Local llama3:8b on CPU runs ~25-30 tok/s, so keeping
        // outputs at 1-8 tokens keeps the suite under a few seconds total.
        $h->add('agent.single.llm.call.in.worker.returns.completion', function (ExecutionScope $_): Result {
            $app = self::buildAppWithWorkers(agents: 1);
            try {
                $scope = $app->createScope();
                $task = new AgentTask(
                    role: 'echoer',
                    systemPrompt: 'Reply with exactly one word: PONG. No punctuation. No explanation.',
                    transcript: [['role' => 'user', 'content' => 'ping']],
                    config: $this->config,
                    maxTokens: 4,
                );
                $reply = (string) $scope->inWorker($task);
                $scope->dispose();
            } finally {
                $app->shutdown();
            }
            $contains = stripos($reply, 'PONG') !== false;
            return $contains
                ? Result::pass()
                : Result::fail("expected reply containing PONG, got: {$reply}");
        });

        $h->add('agent.parent.coordinates.two.children.in.parallel', function (ExecutionScope $_): Result {
            $app = self::buildAppWithWorkers(agents: 2);
            try {
                $scope = $app->createScope();
                $config = $this->config;
                $tasks = [
                    'yes' => static fn(ExecutionScope $s): string => (string) $s->inWorker(new AgentTask(
                        role: 'yes',
                        systemPrompt: 'Reply with exactly one word: YES. Nothing else.',
                        transcript: [['role' => 'user', 'content' => 'answer']],
                        config: $config,
                        maxTokens: 4,
                    )),
                    'no' => static fn(ExecutionScope $s): string => (string) $s->inWorker(new AgentTask(
                        role: 'no',
                        systemPrompt: 'Reply with exactly one word: NO. Nothing else.',
                        transcript: [['role' => 'user', 'content' => 'answer']],
                        config: $config,
                        maxTokens: 4,
                    )),
                ];
                $start = microtime(true);
                $results = $scope->concurrent($tasks);
                $elapsed = microtime(true) - $start;
                $scope->dispose();
            } finally {
                $app->shutdown();
            }
            $errs = [
                Assertions::equals(2, count($results), 'two replies'),
                stripos($results['yes'] ?? '', 'YES') !== false ? null : "yes branch returned: {$results['yes']}",
                stripos($results['no'] ?? '', 'NO') !== false ? null : "no branch returned: {$results['no']}",
            ];
            foreach ($errs as $e) {
                if ($e !== null) {
                    return Result::fail($e);
                }
            }
            return $elapsed < 15.0
                ? Result::pass()
                : Result::fail(sprintf('elapsed %.2fs is suspiciously high', $elapsed));
        });

        $h->add('agent.multi.round.debate.parent.relays.between.children', function (ExecutionScope $_): Result {
            // Parent orchestrates A -> B -> A. Each turn the parent appends the
            // prior reply to the receiving agent's transcript. Proves bidirectional
            // coordination via parent-mediated relay; assertions look at the
            // transcript shape and turn order, not the prose.
            $app = self::buildAppWithWorkers(agents: 2);
            try {
                $scope = $app->createScope();
                $config = $this->config;
                $aPrompt = 'Reply with exactly one word: AYE. Nothing else.';
                $bPrompt = 'Reply with exactly one word: NAY. Nothing else.';

                $transcript = [];
                $aHistory = [];
                $bHistory = [];

                $aHistory[] = ['role' => 'user', 'content' => 'speak'];
                $a1 = (string) $scope->inWorker(new AgentTask(
                    role: 'aye',
                    systemPrompt: $aPrompt,
                    transcript: $aHistory,
                    config: $config,
                    maxTokens: 4,
                ));
                $aHistory[] = ['role' => 'assistant', 'content' => $a1];
                $transcript[] = ['speaker' => 'aye', 'text' => $a1];

                $bHistory[] = ['role' => 'user', 'content' => "they said: {$a1}"];
                $b1 = (string) $scope->inWorker(new AgentTask(
                    role: 'nay',
                    systemPrompt: $bPrompt,
                    transcript: $bHistory,
                    config: $config,
                    maxTokens: 4,
                ));
                $bHistory[] = ['role' => 'assistant', 'content' => $b1];
                $transcript[] = ['speaker' => 'nay', 'text' => $b1];

                $aHistory[] = ['role' => 'user', 'content' => "they replied: {$b1}"];
                $a2 = (string) $scope->inWorker(new AgentTask(
                    role: 'aye',
                    systemPrompt: $aPrompt,
                    transcript: $aHistory,
                    config: $config,
                    maxTokens: 4,
                ));
                $transcript[] = ['speaker' => 'aye', 'text' => $a2];

                $scope->dispose();
            } finally {
                $app->shutdown();
            }
            $errs = [
                Assertions::equals(3, count($transcript), 'three turns recorded'),
                Assertions::equals('aye', $transcript[0]['speaker'], 'turn 1 = aye'),
                Assertions::equals('nay', $transcript[1]['speaker'], 'turn 2 = nay'),
                Assertions::equals('aye', $transcript[2]['speaker'], 'turn 3 = aye'),
                trim($transcript[0]['text']) !== '' ? null : 'turn 1 empty',
                trim($transcript[1]['text']) !== '' ? null : 'turn 2 empty',
                trim($transcript[2]['text']) !== '' ? null : 'turn 3 empty',
            ];
            foreach ($errs as $e) {
                if ($e !== null) {
                    return Result::fail($e);
                }
            }
            return Result::pass();
        });

        $h->add('agent.cancellation.aborts.in.flight.completion', function (ExecutionScope $_): Result {
            // Parent cancels its scope while an LLM call is in flight. The
            // request needs to be long enough that natural completion is
            // distinguishable from a successful cancel. With llama3:8b at
            // ~25 tok/s, max_tokens=128 → ~5-7s natural completion. Cancel
            // fires at 150ms; if it propagates, the parent returns near
            // immediately. Threshold <2.5s catches the impl gap without
            // flaking on warm-cache fast paths.
            $app = self::buildAppWithWorkers(agents: 1);
            try {
                $scope = $app->createScope();
                $token = $scope->cancellation();
                Coroutine::create(static function () use ($token): void {
                    Coroutine::usleep(150_000);
                    $token->cancel();
                });
                $task = new AgentTask(
                    role: 'slow',
                    systemPrompt: 'Count from 1 to 100, one number per line.',
                    transcript: [['role' => 'user', 'content' => 'go']],
                    config: $this->config,
                    maxTokens: 128,
                );
                $start = microtime(true);
                try {
                    $scope->inWorker($task);
                } catch (\Throwable) {
                    // expected — cancel should surface as a thrown error
                }
                $elapsed = microtime(true) - $start;
                $scope->dispose();
            } finally {
                $app->shutdown();
            }
            return $elapsed < 2.5
                ? Result::pass()
                : Result::fail(sprintf('elapsed %.2fs — cancel did not propagate fast enough', $elapsed));
        });
    }
}
