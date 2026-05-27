<?php

declare(strict_types=1);

namespace Phalanx\Dory\Orchestration;

use Closure;
use Phalanx\Cancellation\Cancelled;
use Phalanx\Concurrency\RetryPolicy;
use Phalanx\Scope\ExecutionScope;
use Throwable;

final class AttemptBuilder
{
    private ?RetryPolicy $retryPolicy = null;
    private ?float $timeoutSeconds = null;
    private ?string $singleflightKey = null;
    private ?Closure $catchHandler = null;
    private ?Closure $finallyCallback = null;

    public function __construct(
        private ExecutionScope $scope,
        private(set) Closure $task,
    ) {
    }

    public function retry(int $times, ?RetryPolicy $policy = null): self
    {
        $this->retryPolicy = $policy ?? RetryPolicy::exponential($times);
        return $this;
    }

    public function timeout(float $seconds): self
    {
        $this->timeoutSeconds = $seconds;
        return $this;
    }

    public function singleflight(string $key): self
    {
        $this->singleflightKey = $key;
        return $this;
    }

    public function catch(Closure $handler): self
    {
        $this->catchHandler = $handler;
        return $this;
    }

    public function finally(Closure $callback): self
    {
        $this->finallyCallback = $callback;
        return $this;
    }

    public function run(): mixed
    {
        $task = $this->task;
        $scope = $this->scope;

        $execute = static function () use ($scope, $task): mixed {
            return $scope->execute($task);
        };

        if ($this->retryPolicy !== null) {
            $policy = $this->retryPolicy;
            $prev = $execute;
            $execute = static function () use ($scope, $prev, $policy): mixed {
                return $scope->retry($prev, $policy);
            };
        }

        if ($this->timeoutSeconds !== null) {
            $seconds = $this->timeoutSeconds;
            $prev = $execute;
            $execute = static function () use ($scope, $prev, $seconds): mixed {
                return $scope->timeout($seconds, $prev);
            };
        }

        if ($this->singleflightKey !== null) {
            $key = $this->singleflightKey;
            $prev = $execute;
            $execute = static function () use ($scope, $prev, $key): mixed {
                return $scope->singleflight($key, $prev);
            };
        }

        $catchHandler = $this->catchHandler;
        $finallyCallback = $this->finallyCallback;

        if ($catchHandler !== null || $finallyCallback !== null) {
            try {
                return $execute();
            } catch (Throwable $e) {
                if ($e instanceof Cancelled) {
                    throw $e;
                }
                if ($catchHandler !== null) {
                    return $catchHandler($e);
                }
                throw $e;
            } finally {
                if ($finallyCallback !== null) {
                    $finallyCallback();
                }
            }
        }

        return $execute();
    }
}
