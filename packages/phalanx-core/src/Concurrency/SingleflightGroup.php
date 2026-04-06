<?php

declare(strict_types=1);

namespace Phalanx\Concurrency;

use Phalanx\Service\FiberScopeRegistry;
use React\Promise\Deferred;

use function React\Async\await;

final class SingleflightGroup
{
    /** @var array<string, Deferred> */
    private array $inFlight = [];

    public function do(string $key, callable $execute): mixed
    {
        if (isset($this->inFlight[$key])) {
            $promise = $this->inFlight[$key]->promise();
            $scope = FiberScopeRegistry::current();

            /** Raw await fallback: no scope = no cancellation token to race against. */
            return $scope !== null
                ? $scope->await($promise)
                : await($promise);
        }

        $deferred = new Deferred();
        $this->inFlight[$key] = $deferred;

        try {
            $result = $execute();
            $deferred->resolve($result);

            return $result;
        } catch (\Throwable $e) {
            $deferred->reject($e);

            throw $e;
        } finally {
            unset($this->inFlight[$key]);
        }
    }

    public function pending(): int
    {
        return count($this->inFlight);
    }
}
