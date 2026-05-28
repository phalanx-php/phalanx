<?php

declare(strict_types=1);

namespace Phalanx\Swoole\Mvp\Runtime;

use OpenSwoole\Coroutine\Channel;
use Phalanx\Swoole\Mvp\Scope\Disposable;

final class KeyedLockRegistry
{
    /** @var array<class-string, array<string, \SplQueue<Channel>>> */
    private array $registry = [];

    public static function hashKeys(array $keys): string
    {
        return serialize($keys);
    }

    /**
     * @param class-string $resource
     */
    public function acquire(string $resource, string $keyHash, Disposable $scope): void
    {
        if (! isset($this->registry[$resource][$keyHash])) {
            $this->registry[$resource][$keyHash] = new \SplQueue();
            return;
        }

        $ch = new Channel(1);
        $this->registry[$resource][$keyHash]->enqueue($ch);
        $scope->onDispose(static function () use ($ch): void {
            $ch->close();
        });

        $signal = $ch->pop();
        if ($signal === false) {
            throw new CancellationException(
                "Lock acquisition cancelled for {$resource}#{$keyHash}."
            );
        }
    }

    /**
     * @param list<array{class-string, string}> $resourceKeys
     */
    public function acquireMany(array $resourceKeys, Disposable $scope): void
    {
        $unique = [];
        foreach ($resourceKeys as [$r, $k]) {
            $unique[$r . "\0" . $k] = [$r, $k];
        }
        $sorted = array_values($unique);
        usort($sorted, static fn(array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        foreach ($sorted as [$resource, $keyHash]) {
            $this->acquire($resource, $keyHash, $scope);
        }
    }

    /**
     * @param class-string $resource
     */
    public function release(string $resource, string $keyHash): void
    {
        $queue = $this->registry[$resource][$keyHash] ?? null;
        if ($queue === null) {
            return;
        }
        while (! $queue->isEmpty()) {
            $next = $queue->dequeue();
            if ($next->push(true) === true) {
                return;
            }
        }
        unset($this->registry[$resource][$keyHash]);
        if ($this->registry[$resource] === []) {
            unset($this->registry[$resource]);
        }
    }

    /**
     * @param list<array{class-string, string}> $resourceKeys
     */
    public function releaseMany(array $resourceKeys): void
    {
        $unique = [];
        foreach ($resourceKeys as [$r, $k]) {
            $unique[$r . "\0" . $k] = [$r, $k];
        }
        foreach ($unique as [$resource, $keyHash]) {
            $this->release($resource, $keyHash);
        }
    }

    /**
     * @return array{resources: int, keys: int}
     */
    public function snapshot(): array
    {
        $keyCount = 0;
        foreach ($this->registry as $perResource) {
            $keyCount += count($perResource);
        }
        return ['resources' => count($this->registry), 'keys' => $keyCount];
    }
}
