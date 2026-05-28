<?php

declare(strict_types=1);

namespace Phalanx\Swoole\Mvp\Runtime;

use OpenSwoole\Coroutine as Co;
use OpenSwoole\Coroutine\Channel;
use Phalanx\Swoole\Mvp\Service\Container;

final class Dispatcher
{
    /**
     * @param array<class-string, TaskMetadata> $metadata
     */
    public function __construct(
        private readonly array $metadata,
        private readonly Container $container,
        private readonly KeyedLockRegistry $registry,
    ) {}

    public function dispatch(object $task): mixed
    {
        $meta = $this->metadataFor($task);
        $scope = new RuntimeScope($this, $this->container, $meta, Co::getCid());

        $resourceKeys = $this->resourceKeysFor($task, $meta);
        if ($resourceKeys !== []) {
            $this->registry->acquireMany($resourceKeys, $scope);
        }
        try {
            return $task($scope);
        } finally {
            if ($resourceKeys !== []) {
                $this->registry->releaseMany($resourceKeys);
            }
            $scope->dispose();
        }
    }

    public function dispatchIsolated(object $task): mixed
    {
        $future = new Future();
        $cid = go(function () use ($task, $future): void {
            try {
                $future->settle($this->dispatch($task));
            } catch (\Throwable $e) {
                $future->fail($e);
            }
        });
        if ($cid === false) {
            throw new \RuntimeException('Failed to spawn isolated coroutine.');
        }
        return $future->wait();
    }

    /**
     * @param array<int|string, object> $tasks
     * @return array<int|string, mixed>
     */
    public function parallel(array $tasks): array
    {
        if ($tasks === []) {
            return [];
        }
        $wg = new WaitGroup();
        $results = [];
        $cids = [];
        $firstError = null;

        foreach ($tasks as $key => $task) {
            $wg->add();
            $cid = go(function () use ($task, $key, $wg, &$results, &$firstError, &$cids): void {
                try {
                    $results[$key] = $this->dispatch($task);
                } catch (\Throwable $e) {
                    if ($firstError === null) {
                        $firstError = $e;
                        foreach ($cids as $other) {
                            if ($other !== Co::getCid() && Co::exists($other)) {
                                Co::cancel($other);
                            }
                        }
                    }
                } finally {
                    $wg->done();
                }
            });
            if ($cid === false) {
                $wg->done();
                throw new \RuntimeException('Failed to spawn parallel coroutine.');
            }
            $cids[] = $cid;
        }

        $wg->wait();
        if ($firstError !== null) {
            throw $firstError;
        }
        return $results;
    }

    /**
     * @param array<int|string, object> $tasks
     */
    public function firstOf(array $tasks): mixed
    {
        if ($tasks === []) {
            throw new \InvalidArgumentException('firstOf requires at least one task.');
        }
        $resultCh = new Channel(1);
        $cids = [];

        foreach ($tasks as $task) {
            $cid = go(function () use ($task, $resultCh): void {
                try {
                    $r = $this->dispatch($task);
                    $resultCh->push(['ok', $r]);
                } catch (\Throwable $e) {
                    $resultCh->push(['err', $e]);
                }
            });
            if ($cid === false) {
                throw new \RuntimeException('Failed to spawn firstOf coroutine.');
            }
            $cids[] = $cid;
        }

        $first = $resultCh->pop();
        foreach ($cids as $cid) {
            if (Co::exists($cid)) {
                Co::cancel($cid);
            }
        }

        [$kind, $value] = $first;
        if ($kind === 'err') {
            throw $value;
        }
        return $value;
    }

    /**
     * @param iterable<mixed> $items
     * @param \Closure(mixed): object $factory
     * @return list<mixed>
     */
    public function all(iterable $items, \Closure $factory): array
    {
        $itemList = is_array($items) ? array_values($items) : iterator_to_array($items, false);
        if ($itemList === []) {
            return [];
        }
        $sampleTask = $factory($itemList[0]);
        $capacity = $this->inferCapacity($sampleTask);
        $semaphore = new Channel($capacity);
        for ($i = 0; $i < $capacity; $i++) {
            $semaphore->push(true);
        }

        $wg = new WaitGroup();
        $results = [];
        $firstError = null;

        $dispatch = function (object $task, int $i) use (&$results, $semaphore, $wg, &$firstError): void {
            $semaphore->pop();
            try {
                $results[$i] = $this->dispatch($task);
            } catch (\Throwable $e) {
                $firstError ??= $e;
            } finally {
                $semaphore->push(true);
                $wg->done();
            }
        };

        foreach ($itemList as $i => $item) {
            $wg->add();
            $task = ($i === 0) ? $sampleTask : $factory($item);
            $cid = go(static fn() => $dispatch($task, $i));
            if ($cid === false) {
                $wg->done();
                throw new \RuntimeException('Failed to spawn coroutine in all().');
            }
        }

        $wg->wait();
        if ($firstError !== null) {
            throw $firstError;
        }
        ksort($results);
        return array_values($results);
    }

    private function inferCapacity(object $task): int
    {
        $meta = $this->metadataFor($task);
        $candidates = [];
        foreach ($meta->writes as $resource => $_) {
            $cap = $this->container->descriptors()[$resource]->capacity ?? null;
            if ($cap !== null) {
                $candidates[] = $cap;
            }
        }
        foreach ($meta->reads as $resource) {
            $cap = $this->container->descriptors()[$resource]->capacity ?? null;
            if ($cap !== null) {
                $candidates[] = $cap;
            }
        }
        return $candidates === [] ? 10 : min($candidates);
    }

    private function metadataFor(object $task): TaskMetadata
    {
        $class = $task::class;
        return $this->metadata[$class] ?? throw new CompileException(
            "Task class {$class} was not registered/compiled."
        );
    }

    /**
     * @return list<array{class-string, string}>
     */
    private function resourceKeysFor(object $task, TaskMetadata $meta): array
    {
        if ($meta->profile !== TaskMetadata::PROFILE_WRITES || $meta->keyExtractor === null) {
            return [];
        }
        $values = ($meta->keyExtractor)($task);
        $pairs = [];
        foreach ($meta->writes as $resource => $properties) {
            if ($properties === []) {
                $pairs[] = [$resource, KeyedLockRegistry::hashKeys(['__resource_global__'])];
                continue;
            }
            foreach ($properties as $prop) {
                $pairs[] = [$resource, KeyedLockRegistry::hashKeys([$values[$prop]])];
            }
        }
        return $pairs;
    }
}
