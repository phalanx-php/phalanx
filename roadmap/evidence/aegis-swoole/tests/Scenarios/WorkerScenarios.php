<?php

declare(strict_types=1);

namespace AegisSwoole\Tests\Scenarios;

use AegisSwoole\Application;
use AegisSwoole\Scope\ExecutionScope;
use AegisSwoole\Service\ServiceBundle;
use AegisSwoole\Service\Services;
use AegisSwoole\Tests\Assertions;
use AegisSwoole\Tests\Fixtures\SerializableEchoTask;
use AegisSwoole\Tests\Fixtures\SerializableFailingTask;
use AegisSwoole\Tests\Harness;
use AegisSwoole\Tests\Result;
use AegisSwoole\Worker\DispatchStrategy;
use AegisSwoole\Worker\Mailbox;
use AegisSwoole\Worker\OverflowException;
use AegisSwoole\Worker\ParallelConfig;
use AegisSwoole\Worker\ParallelWorkerDispatch;
use AegisSwoole\Worker\Protocol\TaskRequest;
use AegisSwoole\Worker\WorkerSupervisor;
use RuntimeException;

class WorkerScenarios
{
    private static function buildApp(int $agents, int $mailboxLimit = 64): Application
    {
        $bundle = new class implements ServiceBundle {
            public function services(Services $services, array $context): void
            {
            }
        };
        $config = new ParallelConfig(
            agents: $agents,
            mailboxLimit: $mailboxLimit,
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
        $h->add('worker.basic.roundtrip.runs.in.child.process', function (ExecutionScope $_): Result {
            $app = self::buildApp(agents: 1);
            try {
                $scope = $app->createScope();
                $result = $scope->inWorker(new SerializableEchoTask('hello'));
                $scope->dispose();
            } finally {
                $app->shutdown();
            }
            $errs = [
                Assertions::equals('hello', $result['message'] ?? null, 'echoed message'),
                Assertions::notEquals(getmypid(), $result['pid'] ?? null, 'ran in different PID'),
            ];
            foreach ($errs as $e) {
                if ($e !== null) {
                    return Result::fail($e);
                }
            }
            return Result::pass();
        });

        $h->add('worker.error.propagates.back.to.parent', function (ExecutionScope $_): Result {
            $app = self::buildApp(agents: 1);
            try {
                $scope = $app->createScope();
                $err = Assertions::throws(
                    RuntimeException::class,
                    static fn() => $scope->inWorker(new SerializableFailingTask('boom')),
                );
                $scope->dispose();
            } finally {
                $app->shutdown();
            }
            return $err === null ? Result::pass() : Result::fail($err);
        });

        $h->add('worker.closure.rejected.at.boundary', function (ExecutionScope $_): Result {
            $app = self::buildApp(agents: 1);
            try {
                $scope = $app->createScope();
                $closure = static fn(): int => 1;
                $err = Assertions::throws(
                    RuntimeException::class,
                    static fn() => $scope->inWorker($closure),
                );
                $scope->dispose();
            } finally {
                $app->shutdown();
            }
            return $err === null ? Result::pass() : Result::fail($err);
        });

        $h->add('worker.pool.distributes.across.agents', function (ExecutionScope $_): Result {
            $app = self::buildApp(agents: 2);
            try {
                $scope = $app->createScope();
                $tasks = [];
                for ($i = 0; $i < 4; $i++) {
                    $tasks["t{$i}"] = static fn(ExecutionScope $s): array
                        => $s->inWorker(new SerializableEchoTask("msg{$i}"));
                }
                $results = $scope->concurrent($tasks);
                $scope->dispose();
            } finally {
                $app->shutdown();
            }
            $pids = array_unique(array_map(static fn($r): int => (int) ($r['pid'] ?? 0), $results));
            $err = Assertions::equals(2, count($pids), 'used both worker PIDs');
            return $err === null ? Result::pass() : Result::fail($err);
        });

        $h->add('worker.mailbox.overflow.throws', function (ExecutionScope $_): Result {
            // Direct unit-style: prove Mailbox::push throws OverflowException at the
            // declared limit. The dispatch-time variant is too race-sensitive to
            // assert reliably (writer coroutine drains the slot between pushes).
            $mb = new Mailbox(2);
            $mb->push(new TaskRequest('1', ''));
            $mb->push(new TaskRequest('2', ''));
            $err = Assertions::throws(
                OverflowException::class,
                static fn() => $mb->push(new TaskRequest('3', '')),
            );
            $mb->close();
            return $err === null ? Result::pass() : Result::fail($err);
        });
    }
}
